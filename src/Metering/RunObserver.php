<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Metering;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Tools\ToolNameResolver;
use Padosoft\LaravelAiFinOps\Contracts\RunEventRecorder;
use Padosoft\LaravelAiFinOps\Data\RunEvent;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Throwable;

/**
 * The run-shape hook on the laravel/ai lifecycle, added in laravel/ai 0.11.
 *
 * {@see MeteringListener} answers "what did this run cost", from the one event
 * that fires when a run succeeds. This answers the three questions that event
 * cannot:
 *
 *  - **Where did the cost go?** Usage arrives per step, so a five-step run stops
 *    being one number.
 *  - **What did a run that failed cost?** A run that dies mid-way never emits
 *    AgentPrompted, so every token it burned before dying used to be invisible.
 *    Steps are accumulated as they complete and billed when AgentFailed lands.
 *  - **Which tool is slow, and which one throws?** Tool wall time and tool
 *    exceptions are not on the response object at all.
 *
 * Everything here is best-effort: a metering hook must never be the reason a run
 * fails, so each handler swallows its own errors.
 */
class RunObserver
{
    /**
     * Usage accumulated per in-flight run, so a terminal failure can still be
     * billed for the steps that did complete.
     *
     * @var array<string, array{tokens: TokenUsage, provider: ?string, model: ?string, steps: int}>
     */
    private array $inFlight = [];

    /**
     * How many concurrent runs to remember. A queue worker lives for hours and a
     * run that neither completes nor fails (killed process, fatal error) would
     * otherwise leak its entry forever; the oldest is evicted past this bound.
     */
    private const MAX_IN_FLIGHT = 256;

    public function __construct(
        private readonly RunEventRecorder $recorder,
        private readonly MeteringListener $metering,
        private readonly Config $config,
        private readonly PricingRegistry $pricing,
        private readonly CostCalculator $costs,
        private readonly TenantResolver $tenants,
        private readonly TraceContext $trace,
    ) {}

    public function handleStepCompleted(StepCompleted $event): void
    {
        $this->guard(function () use ($event): void {
            $tokens = $this->metering->tokensFromUsage($event->response->usage);

            $provider = $event->response->meta->provider ?? $this->providerName($event->provider);

            $this->accumulate($event->invocationId, $tokens, $provider, $event->model);

            $price = $this->pricing->priceFor($event->model, $provider);
            $cost = $this->costs->cost($tokens, $price, $this->baseCurrency());

            $this->recorder->record($this->event(
                invocationId: $event->invocationId,
                kind: RunEventKind::Step,
                status: RunEventStatus::Completed,
                stepNumber: $event->stepNumber,
                isFinalStep: $event->isFinalStep,
                agent: $event->agent::class,
                provider: $provider,
                model: $event->model,
                finishReason: $event->response->finishReason->value,
                tokens: $tokens,
                costTotal: $cost->total,
                currency: $cost->currency,
                durationMs: (int) round($event->time),
            ));
        });
    }

    public function handleStepFailed(StepFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->recorder->record($this->event(
                invocationId: $event->invocationId,
                kind: RunEventKind::Step,
                status: RunEventStatus::Failed,
                stepNumber: $event->stepNumber,
                isFinalStep: $event->isFinalStep,
                agent: $event->agent::class,
                provider: $this->providerName($event->provider),
                model: $event->model,
                durationMs: (int) round($event->time),
                exception: $event->exception,
            ));
        });
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $this->guard(function () use ($event): void {
            $this->recorder->record($this->event(
                invocationId: $event->invocationId,
                kind: RunEventKind::Tool,
                status: RunEventStatus::Completed,
                agent: $event->agent::class,
                toolInvocationId: $event->toolInvocationId,
                toolName: ToolNameResolver::resolve($event->tool),
                durationMs: (int) round($event->time),
            ));
        });
    }

    public function handleToolFailed(ToolFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->recorder->record($this->event(
                invocationId: $event->invocationId,
                kind: RunEventKind::Tool,
                status: RunEventStatus::Failed,
                agent: $event->agent::class,
                toolInvocationId: $event->toolInvocationId,
                toolName: ToolNameResolver::resolve($event->tool),
                durationMs: (int) round($event->time),
                exception: $event->exception,
            ));
        });
    }

    /**
     * A run failed for good. laravel/ai only dispatches this once the failure is
     * terminal — a failover that still has a provider left to try does not reach
     * here — so the tokens accumulated so far are billed exactly once.
     */
    public function handleAgentFailed(AgentFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $state = $this->inFlight[$event->invocationId] ?? null;

            $this->forget($event->invocationId);

            if ($state === null || $state['tokens']->total() === 0) {
                return; // Nothing was spent before it died; a zero row is noise.
            }

            $this->metering->recordFailedRun(
                $event->invocationId,
                $state['provider'] ?? 'unknown',
                $state['model'] ?? 'unknown',
                $state['tokens'],
                $event->exception,
                $state['steps'],
            );
        });
    }

    /**
     * A run succeeded: MeteringListener has already billed it as a whole, so the
     * accumulator only needs releasing.
     */
    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $this->forget($event->invocationId);
    }

    private function accumulate(string $invocationId, TokenUsage $tokens, string $provider, string $model): void
    {
        $existing = $this->inFlight[$invocationId] ?? null;

        if ($existing === null && count($this->inFlight) >= self::MAX_IN_FLIGHT) {
            array_shift($this->inFlight); // Oldest in-flight run wins the eviction.
        }

        $previous = $existing['tokens'] ?? new TokenUsage;

        $this->inFlight[$invocationId] = [
            'tokens' => new TokenUsage(
                input: $previous->input + $tokens->input,
                output: $previous->output + $tokens->output,
                cached: $previous->cached + $tokens->cached,
                reasoning: $previous->reasoning + $tokens->reasoning,
            ),
            'provider' => $provider,
            'model' => $model,
            'steps' => ($existing['steps'] ?? 0) + 1,
        ];
    }

    private function forget(string $invocationId): void
    {
        unset($this->inFlight[$invocationId]);
    }

    private function event(
        string $invocationId,
        RunEventKind $kind,
        RunEventStatus $status,
        ?int $stepNumber = null,
        ?bool $isFinalStep = null,
        ?string $toolInvocationId = null,
        ?string $toolName = null,
        ?string $agent = null,
        ?string $provider = null,
        ?string $model = null,
        ?string $finishReason = null,
        TokenUsage $tokens = new TokenUsage,
        float $costTotal = 0.0,
        ?string $currency = null,
        ?int $durationMs = null,
        ?Throwable $exception = null,
    ): RunEvent {
        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        return new RunEvent(
            invocationId: $invocationId,
            kind: $kind,
            status: $status,
            parentInvocationId: $parentInvocationId,
            parentToolInvocationId: $parentInvocationId === null ? null : $parentToolInvocationId,
            stepNumber: $stepNumber,
            isFinalStep: $isFinalStep,
            toolInvocationId: $toolInvocationId,
            toolName: $toolName,
            agent: $agent,
            provider: $provider,
            model: $model,
            finishReason: $finishReason,
            tokens: $tokens,
            costTotal: $costTotal,
            currency: $currency ?? $this->baseCurrency(),
            durationMs: $durationMs,
            errorClass: $exception === null ? null : $exception::class,
            errorMessage: $this->errorMessage($exception),
            tenantId: $this->trace->tenantId() ?? $this->tenants->resolve(),
            costCenter: $this->trace->costCenter(),
            delegationGrantId: $this->trace->delegationGrantId(),
        );
    }

    /**
     * Exception messages are provider text and can quote the prompt, so capturing
     * them is opt-out and the stored value is bounded.
     */
    private function errorMessage(?Throwable $exception): ?string
    {
        if ($exception === null || ! $this->config->get('ai-finops.run_events.capture_error_messages', true)) {
            return null;
        }

        $limit = max(0, (int) $this->config->get('ai-finops.run_events.error_message_limit', 500));

        return $limit === 0 ? null : mb_substr($exception->getMessage(), 0, $limit);
    }

    /**
     * The provider's label. Neither the TextProvider contract nor the Tool contract
     * declares name(), so the concrete providers that happen to offer one are used
     * when present and the class basename ("OpenAiProvider" -> "OpenAi") otherwise.
     */
    private function providerName(object $provider): string
    {
        if (is_callable([$provider, 'name'])) {
            return (string) $provider->name();
        }

        $basename = class_basename($provider);

        return str_ends_with($basename, 'Provider') ? substr($basename, 0, -8) : $basename;
    }

    private function baseCurrency(): string
    {
        return (string) $this->config->get('ai-finops.currency.base', 'USD');
    }

    /**
     * Run every handler behind the same promise: metering never breaks the run it
     * is watching.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Intentionally ignored: see the class docblock.
        }
    }
}

<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Metering;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Pricing\Cost\CostResolutionService;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Padosoft\LaravelAiFinOps\Support\TraceContext;

/**
 * The single metering hook on the laravel/ai lifecycle. Listens to completion
 * events, maps them to an AiCallEnvelope, prices them via the PricingRegistry +
 * CostCalculator, and records them. Provider-agnostic: any laravel/ai provider
 * (incl. padosoft/laravel-ai-regolo) flows through these events.
 */
class MeteringListener
{
    public function __construct(
        private readonly UsageRecorder $recorder,
        private readonly Config $config,
        private readonly PricingRegistry $pricing,
        private readonly CostResolutionService $costs,
        private readonly TenantResolver $tenants,
        private readonly TraceContext $trace,
    ) {}

    /** Handles AgentPrompted and (via inheritance) AgentStreamed. */
    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $this->recordAgentResponse($event->invocationId, $event->response, $event->prompt);
    }

    public function handleEmbeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->recordEmbeddings($event->invocationId, $event->response, $event->model);
    }

    public function recordAgentResponse(string $invocationId, AgentResponse|StreamedAgentResponse $response, mixed $prompt = null): void
    {
        $envelope = $this->baseEnvelope(
            traceId: $invocationId,
            provider: $response->meta->provider ?? 'unknown',
            model: $response->meta->model ?? 'unknown',
            modality: Modality::Text,
            tokens: $this->tokensFromUsage($response->usage),
            // For the estimation fallback (case c): count the prompt + completion text.
            promptText: $this->normalisePrompt($prompt),
            completionText: $response->text ?? null,
        );

        $this->recorder->record($envelope);
    }

    public function recordEmbeddings(string $invocationId, EmbeddingsResponse $response, string $fallbackModel): void
    {
        $envelope = $this->baseEnvelope(
            traceId: $invocationId,
            provider: $response->meta->provider ?? 'unknown',
            model: $response->meta->model ?? $fallbackModel,
            modality: Modality::Embedding,
            tokens: new TokenUsage(input: $response->tokens),
        );

        $this->recorder->record($envelope);
    }

    /**
     * Best-effort normalisation of a laravel/ai prompt for the token estimator (case c).
     * Returns the prompt as-is when it is already a string or a chat-messages array
     * (both are accepted by TokenEstimator::estimate). Never stored.
     *
     * @return string|array<int,mixed>|null
     */
    private function normalisePrompt(mixed $prompt): string|array|null
    {
        if (is_string($prompt)) {
            return $prompt;
        }

        if (is_array($prompt)) {
            return $prompt;
        }

        if (is_object($prompt) && ($prompt instanceof \Stringable || method_exists($prompt, '__toString'))) {
            return (string) $prompt;
        }

        return null;
    }

    public function tokensFromUsage(Usage $usage): TokenUsage
    {
        return new TokenUsage(
            input: $usage->promptTokens,
            output: $usage->completionTokens,
            cached: $usage->cacheReadInputTokens,
            reasoning: $usage->reasoningTokens,
        );
    }

    private function baseEnvelope(
        string $traceId,
        string $provider,
        string $model,
        Modality $modality,
        TokenUsage $tokens,
        string|array|null $promptText = null,
        ?string $completionText = null,
    ): AiCallEnvelope {
        $currency = (string) $this->config->get('ai-finops.currency.base', 'USD');
        $tenantId = $this->trace->tenantId() ?? $this->tenants->resolve();

        // Draft envelope so the actual-cost resolver can route by provider.
        $draft = new AiCallEnvelope(traceId: $traceId, provider: $provider, model: $model, tokens: $tokens);

        // Cascade: actual billed cost → tokens×tariff → estimated tokens×tariff.
        $resolution = $this->costs->resolve($draft, $tokens, $promptText, $completionText);

        $price = $this->pricing->priceFor($model, $provider);
        $metadata = $this->provenance($price);

        $cost = $resolution->cost;
        $method = $resolution->method;
        $status = CallStatus::Recorded;
        $billedCost = $resolution->billedCost;
        $billedCurrency = $resolution->billedCurrency;

        // Flat-rate subscription coverage: within an active window the call is free
        // (the subscription already paid). Tokens are still recorded for visibility;
        // the would-be price + method stay recorded so "value consumed" is analyzable.
        $covered = $this->activeSubscription($provider, $tenantId, $model);
        if ($covered !== null) {
            $metadata['covered_by'] = $covered->label;
            $billedCost ??= $cost->total; // remember what it would have cost
            $billedCurrency ??= $cost->currency;
            $cost = CostBreakdown::zero($currency);
            $method = CostMethod::Covered;
            $status = CallStatus::Covered;
        }

        return new AiCallEnvelope(
            // Ambient TraceContext (e.g. set by laravel-flow per step) overrides the
            // provider invocation id and adds agentic attribution when present.
            traceId: $this->trace->traceId() ?? $traceId,
            provider: $provider,
            model: $model,
            modality: $modality,
            status: $status,
            tokens: $resolution->tokens,
            cost: $cost,
            agentStep: $this->trace->agentStep(),
            purposeTag: $this->trace->purposeTag(),
            tenantId: $tenantId,
            costCenter: $this->trace->costCenter(),
            metadata: $metadata,
            costMethod: $method,
            tokensEstimated: $resolution->tokensEstimated,
            billedCost: $billedCost,
            billedCurrency: $billedCurrency,
        );
    }

    /**
     * Freeze price provenance onto the ledger row (immutable past truth): which
     * source won, the exact per-token rates applied, the source's sync time, and
     * the real upstream provider when the call was routed via a gateway.
     *
     * @return array<string,mixed>
     */
    private function provenance(?ModelPrice $price): array
    {
        if ($price === null) {
            return [];
        }

        return array_filter([
            'price_source' => $price->source,
            'rate_input' => $price->inputPerToken,
            'rate_output' => $price->outputPerToken,
            'source_synced_at' => $price->syncedAt?->format(\DateTimeInterface::ATOM),
            'upstream_provider' => $price->upstreamProvider,
        ], static fn ($value) => $value !== null);
    }

    private function activeSubscription(string $provider, string|int|null $tenant, string $model): ?SubscriptionWindow
    {
        try {
            return SubscriptionWindow::activeFor(
                $provider,
                $tenant === null ? null : (string) $tenant,
                $model,
                now(),
            );
        } catch (\Throwable) {
            return null; // table not migrated yet / DB unavailable — never break metering
        }
    }
}

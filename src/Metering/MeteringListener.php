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
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
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
        private readonly CostCalculator $calculator,
        private readonly TenantResolver $tenants,
        private readonly TraceContext $trace,
    ) {}

    /** Handles AgentPrompted and (via inheritance) AgentStreamed. */
    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $this->recordAgentResponse($event->invocationId, $event->response);
    }

    public function handleEmbeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->recordEmbeddings($event->invocationId, $event->response, $event->model);
    }

    public function recordAgentResponse(string $invocationId, AgentResponse|StreamedAgentResponse $response): void
    {
        $envelope = $this->baseEnvelope(
            traceId: $invocationId,
            provider: $response->meta->provider ?? 'unknown',
            model: $response->meta->model ?? 'unknown',
            modality: Modality::Text,
            tokens: $this->tokensFromUsage($response->usage),
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
    ): AiCallEnvelope {
        $currency = (string) $this->config->get('ai-finops.currency.base', 'USD');

        $price = $this->pricing->priceFor($model, $provider);
        $cost = $this->calculator->cost($tokens, $price, $currency);
        $tenantId = $this->trace->tenantId() ?? $this->tenants->resolve();

        $metadata = $this->provenance($price);
        $status = CallStatus::Recorded;

        // Flat-rate subscription coverage: within an active window the call is free
        // (the subscription already paid). Tokens are still recorded for visibility;
        // the would-be price stays in metadata so per-model "value consumed" is kept.
        $covered = $this->activeSubscription($provider, $tenantId, $model);
        if ($covered !== null) {
            $metadata['covered_by'] = $covered->label;
            $cost = CostBreakdown::zero($currency);
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
            tokens: $tokens,
            cost: $cost,
            agentStep: $this->trace->agentStep(),
            purposeTag: $this->trace->purposeTag(),
            tenantId: $tenantId,
            costCenter: $this->trace->costCenter(),
            metadata: $metadata,
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

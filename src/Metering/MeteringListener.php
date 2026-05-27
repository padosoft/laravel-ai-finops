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
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
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

        return new AiCallEnvelope(
            // Ambient TraceContext (e.g. set by laravel-flow per step) overrides the
            // provider invocation id and adds agentic attribution when present.
            traceId: $this->trace->traceId() ?? $traceId,
            provider: $provider,
            model: $model,
            modality: $modality,
            status: CallStatus::Recorded,
            tokens: $tokens,
            cost: $cost,
            agentStep: $this->trace->agentStep(),
            purposeTag: $this->trace->purposeTag(),
            tenantId: $this->trace->tenantId() ?? $this->tenants->resolve(),
            costCenter: $this->trace->costCenter(),
            metadata: $price !== null ? ['price_source' => $price->source] : [],
        );
    }
}

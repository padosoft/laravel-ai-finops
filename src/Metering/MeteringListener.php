<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Metering;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
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
        private readonly Container $container,
        private readonly PricingRegistry $pricing,
        private readonly CostCalculator $calculator,
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
            traceId: $traceId,
            provider: $provider,
            model: $model,
            modality: $modality,
            status: CallStatus::Recorded,
            tokens: $tokens,
            cost: $cost,
            tenantId: $this->resolveTenant(),
            metadata: $price !== null ? ['price_source' => $price->source] : [],
        );
    }

    private function resolveTenant(): string|int|null
    {
        if (! $this->config->get('ai-finops.tenancy.enabled', false)) {
            return null;
        }

        $resolver = $this->config->get('ai-finops.tenancy.resolver');

        if ($resolver === null) {
            return null;
        }

        if (is_string($resolver) && $this->container->bound($resolver)) {
            $resolver = $this->container->make($resolver);
        }

        if (is_callable($resolver)) {
            $tenant = $resolver();

            return is_string($tenant) || is_int($tenant) ? $tenant : null;
        }

        return null;
    }
}

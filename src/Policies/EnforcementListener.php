<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Policies;

use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Exceptions\BudgetExceededException;
use Padosoft\LaravelAiFinOps\Support\TenantResolver;
use Throwable;

/**
 * Pre-flight enforcement on the laravel/ai lifecycle. Evaluates kill switches and
 * hard budgets BEFORE the provider call; a Block throws BudgetExceededException
 * (HTTP 402), aborting the call.
 */
class EnforcementListener
{
    public function __construct(
        private readonly PolicyEngine $engine,
        private readonly TenantResolver $tenants,
    ) {}

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        $this->enforce(new AiCallEnvelope(
            traceId: $event->invocationId,
            provider: $this->providerName($event->prompt->provider ?? null),
            model: $event->prompt->model ?? 'unknown',
            modality: Modality::Text,
            tenantId: $this->tenants->resolve(),
        ));
    }

    public function handleGeneratingEmbeddings(GeneratingEmbeddings $event): void
    {
        $this->enforce(new AiCallEnvelope(
            traceId: $event->invocationId,
            provider: $this->providerName($event->provider),
            model: $event->model,
            modality: Modality::Embedding,
            tenantId: $this->tenants->resolve(),
        ));
    }

    public function enforce(AiCallEnvelope $envelope): void
    {
        $decision = $this->engine->evaluate($envelope);

        if ($decision->blocked()) {
            throw new BudgetExceededException($decision->reason, $decision->budgetId);
        }
    }

    private function providerName(mixed $provider): string
    {
        if (is_object($provider) && method_exists($provider, 'name')) {
            try {
                return (string) $provider->name();
            } catch (Throwable) {
                return 'unknown';
            }
        }

        return 'unknown';
    }
}

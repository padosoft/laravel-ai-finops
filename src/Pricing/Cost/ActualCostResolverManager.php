<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\ActualCostResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

/**
 * Routes a call to the actual-cost resolver registered for its provider
 * (`pricing.actual_cost.resolvers`), falling back to the Null resolver. Mirrors
 * the PricingSourceManager pattern.
 */
class ActualCostResolverManager implements ActualCostResolver
{
    /** @param array<string,ActualCostResolver> $resolvers keyed by resolver name */
    public function __construct(
        private readonly array $resolvers,
        private readonly Config $config,
        private readonly ActualCostResolver $default = new NullActualCostResolver,
    ) {}

    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost
    {
        $map = (array) $this->config->get('ai-finops.pricing.actual_cost.resolvers', [
            'openrouter' => 'openrouter',
            'fal' => 'fal',
            'fal_ai' => 'fal',
        ]);

        $name = $map[$call->provider] ?? null;
        $resolver = ($name !== null && isset($this->resolvers[$name])) ? $this->resolvers[$name] : $this->default;

        return $resolver->resolve($call);
    }
}

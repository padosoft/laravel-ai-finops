<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Contracts\ActualCostResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

/** Default: no actual cost available → the cascade falls back to tokens×tariff. */
class NullActualCostResolver implements ActualCostResolver
{
    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost
    {
        return null;
    }
}

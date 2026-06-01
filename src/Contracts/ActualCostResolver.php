<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Pricing\Cost\ResolvedActualCost;

interface ActualCostResolver
{
    /**
     * The provider's ACTUAL billed cost for this call, or null when unavailable
     * (caller then falls back to tokens×tariff). Implementations must not throw on
     * missing data — return null instead.
     */
    public function resolve(AiCallEnvelope $call): ?ResolvedActualCost;
}

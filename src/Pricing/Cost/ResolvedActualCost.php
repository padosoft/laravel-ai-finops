<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Data\TokenUsage;

/**
 * The real billed cost of a call as reported by (or reconstructed from) the
 * provider — distinct from a tariff estimate. `tokens` are the native token counts
 * when the provider returned them (null for unit-priced media like fal).
 */
final readonly class ResolvedActualCost
{
    public function __construct(
        public float $amount,
        public string $currency,
        public ?TokenUsage $tokens = null,
        public string $source = 'provider',
    ) {}
}

<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;

/**
 * Outcome of the cost cascade: the cost to record, how it was derived, the tokens
 * (actual or estimated), and — when the provider reported it — the real billed cost.
 */
final readonly class Resolution
{
    public function __construct(
        public CostBreakdown $cost,
        public CostMethod $method,
        public TokenUsage $tokens,
        public bool $tokensEstimated = false,
        public ?float $billedCost = null,
        public ?string $billedCurrency = null,
    ) {}
}

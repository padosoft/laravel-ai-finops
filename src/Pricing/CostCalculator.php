<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing;

use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;

/**
 * Turns token counts + a ModelPrice into a CostBreakdown. Prompt tokens are
 * assumed to INCLUDE cached-read tokens, which are billed at the (cheaper) cache
 * rate when available; the rest bill at the standard input rate. `cached` in the
 * breakdown is the cached sub-cost (already part of `input`), not an extra.
 */
class CostCalculator
{
    public function cost(TokenUsage $tokens, ?ModelPrice $price, string $fallbackCurrency = 'USD'): CostBreakdown
    {
        if ($price === null) {
            return CostBreakdown::zero($fallbackCurrency);
        }

        $cachedTokens = max(0, $tokens->cached);
        $nonCachedInput = max(0, $tokens->input - $cachedTokens);
        $cacheRate = $price->cacheReadPerToken ?? $price->inputPerToken;

        $cachedCost = $cachedTokens * $cacheRate;
        $inputCost = ($nonCachedInput * $price->inputPerToken) + $cachedCost;
        $outputCost = $tokens->output * $price->outputPerToken;

        return new CostBreakdown(
            total: $inputCost + $outputCost,
            input: $inputCost,
            output: $outputCost,
            cached: $cachedCost,
            currency: $price->currency,
        );
    }
}

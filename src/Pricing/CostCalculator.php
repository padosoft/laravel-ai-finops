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

    /**
     * Apply a per-provider account-level overhead (e.g. OpenRouter's ~5.5% credit
     * top-up fee) for ESTIMATES only. The raw metered ledger never uses this — it
     * records the pass-through per-token cost. Returns the cost unchanged when no
     * fee is configured for the provider.
     */
    public function withOverhead(float $cost, ?string $provider): float
    {
        if ($provider === null) {
            return $cost;
        }

        $fees = (array) config('ai-finops.pricing.fees', []);
        $pct = (float) ($fees[$provider]['markup_pct'] ?? 0.0);

        if ($pct <= 0.0) {
            return $cost;
        }

        return $cost * (1.0 + $pct / 100.0);
    }
}

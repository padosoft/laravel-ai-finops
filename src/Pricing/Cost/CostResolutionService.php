<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Pricing\Cost;

use Illuminate\Contracts\Config\Repository as Config;
use Padosoft\LaravelAiFinOps\Contracts\ActualCostResolver;
use Padosoft\LaravelAiFinOps\Contracts\TokenEstimator;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;

/**
 * The cost cascade — pick the truest number available, and record HOW:
 *   (a) ACTUAL billed cost from the provider (ActualCostResolver) → method=actual;
 *   (b) ACTUAL tokens × our tariff → method=computed;
 *   (c) ESTIMATED tokens × our tariff → method=estimated, tokens_estimated=true.
 * Subscription coverage (M8) is applied by the caller AFTER this (it zeroes the cost
 * and sets method=covered, but keeps the would-be billed amount for analytics).
 */
class CostResolutionService
{
    public function __construct(
        private readonly ActualCostResolver $actual,
        private readonly PricingRegistry $pricing,
        private readonly CostCalculator $calculator,
        private readonly TokenEstimator $estimator,
        private readonly Config $config,
    ) {}

    /**
     * @param  string|array<int,mixed>|null  $promptText  prompt (for case c input estimation)
     * @param  string|null  $completionText  completion text (for case c output estimation)
     */
    public function resolve(
        AiCallEnvelope $call,
        TokenUsage $usage,
        string|array|null $promptText = null,
        ?string $completionText = null,
    ): Resolution {
        $base = (string) $this->config->get('ai-finops.currency.base', 'USD');
        $price = $this->pricing->priceFor($call->model, $call->provider);

        // (a) Actual billed cost from the provider.
        $actual = $this->actual->resolve($call);
        if ($actual !== null) {
            $tokens = $actual->tokens ?? $usage;
            $breakdown = $this->calculator->cost($tokens, $price, $base);
            // Keep the tariff input/output split for analytics ONLY when it is in the
            // same currency as the billed amount; otherwise don't mix currencies.
            $sameCurrency = $breakdown->currency === $actual->currency;
            $cost = new CostBreakdown(
                total: $actual->amount,                              // billed amount is authoritative
                input: $sameCurrency ? $breakdown->input : 0.0,
                output: $sameCurrency ? $breakdown->output : 0.0,
                cached: $sameCurrency ? $breakdown->cached : 0.0,
                currency: $actual->currency,
            );

            return new Resolution(
                cost: $cost,
                method: CostMethod::Actual,
                tokens: $tokens,
                tokensEstimated: false,
                billedCost: $actual->amount,
                billedCurrency: $actual->currency,
            );
        }

        // (b) Actual tokens × tariff.
        if ($usage->input > 0 || $usage->output > 0 || $usage->cached > 0 || $usage->reasoning > 0) {
            return new Resolution(
                cost: $this->calculator->cost($usage, $price, $base),
                method: CostMethod::Computed,
                tokens: $usage,
                tokensEstimated: false,
            );
        }

        // (c) Estimated tokens × tariff.
        if ((bool) $this->config->get('ai-finops.pricing.token_estimation.enabled', true)) {
            $input = $promptText !== null ? $this->estimator->estimate($promptText, $call->model)->input : 0;
            $output = $completionText !== null && $completionText !== ''
                ? $this->estimator->estimate($completionText, $call->model)->input
                : (int) round($input * (float) $this->config->get('ai-finops.pricing.token_estimation.expected_output_ratio', 1.0));

            $estimated = new TokenUsage(input: $input, output: $output);

            return new Resolution(
                cost: $this->calculator->cost($estimated, $price, $base),
                method: CostMethod::Estimated,
                tokens: $estimated,
                tokensEstimated: true,
            );
        }

        // Nothing to price → zero, recorded as computed-with-zero (never a fabricated number).
        return new Resolution(
            cost: CostBreakdown::zero($base),
            method: CostMethod::Computed,
            tokens: $usage,
            tokensEstimated: false,
        );
    }
}

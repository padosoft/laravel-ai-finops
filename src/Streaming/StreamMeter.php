<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Streaming;

use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;

/**
 * Stateful live meter for a streaming response. The host feeds prompt tokens once
 * and output tokens as chunks arrive; `shouldCutoff()` flags when the running cost
 * reaches the remaining budget so the caller can abort the stream mid-flight.
 */
class StreamMeter
{
    private int $input = 0;

    private int $output = 0;

    private int $cached = 0;

    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly ?ModelPrice $price,
        private readonly float $remainingBudget = INF,
        private readonly string $currency = 'USD',
    ) {}

    public function setPromptTokens(int $input, int $cached = 0): self
    {
        $this->input = max(0, $input);
        $this->cached = max(0, $cached);

        return $this;
    }

    public function addOutputTokens(int $delta): self
    {
        $this->output += max(0, $delta);

        return $this;
    }

    public function currentCost(): CostBreakdown
    {
        return $this->calculator->cost(
            new TokenUsage(input: $this->input, output: $this->output, cached: $this->cached),
            $this->price,
            $this->currency,
        );
    }

    public function shouldCutoff(): bool
    {
        if (! is_finite($this->remainingBudget)) {
            return false;
        }

        return $this->currentCost()->total >= $this->remainingBudget;
    }
}

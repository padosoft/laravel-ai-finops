<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;
use Padosoft\LaravelAiFinOps\Streaming\StreamMeter;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class StreamMeterTest extends TestCase
{
    private function meter(float $remaining = INF): StreamMeter
    {
        $price = new ModelPrice(model: 'gpt-5.1', inputPerToken: 0.000002, outputPerToken: 0.00001);

        return new StreamMeter(new CostCalculator, $price, $remaining, 'USD');
    }

    public function test_accumulates_cost_as_output_streams(): void
    {
        $meter = $this->meter()->setPromptTokens(1000);
        $meter->addOutputTokens(100)->addOutputTokens(100);

        // input 1000*2e-6 = 0.002 ; output 200*1e-5 = 0.002 ; total 0.004
        $this->assertEqualsWithDelta(0.004, $meter->currentCost()->total, 1e-9);
    }

    public function test_should_cutoff_when_running_cost_reaches_remaining_budget(): void
    {
        $meter = $this->meter(0.0025)->setPromptTokens(1000); // input cost already 0.002

        $this->assertFalse($meter->shouldCutoff());

        $meter->addOutputTokens(100); // +0.001 → 0.003 >= 0.0025
        $this->assertTrue($meter->shouldCutoff());
    }

    public function test_no_cutoff_with_infinite_budget(): void
    {
        $meter = $this->meter()->setPromptTokens(1_000_000);
        $meter->addOutputTokens(1_000_000);

        $this->assertFalse($meter->shouldCutoff());
    }
}

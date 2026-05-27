<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Pricing\ModelPrice;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_computes_input_output_and_cached_costs(): void
    {
        $calc = new CostCalculator;

        $price = new ModelPrice(
            model: 'gpt-x',
            inputPerToken: 0.000002,
            outputPerToken: 0.000008,
            cacheReadPerToken: 0.0000005,
        );

        // 1000 prompt tokens, 200 of which are cached reads; 500 completion tokens.
        $tokens = new TokenUsage(input: 1000, output: 500, cached: 200);

        $cost = $calc->cost($tokens, $price);

        // (800 * 2e-6) + (200 * 5e-7) = 0.0016 + 0.0001 = 0.0017
        $this->assertEqualsWithDelta(0.0017, $cost->input, 1e-12);
        // 500 * 8e-6 = 0.004
        $this->assertEqualsWithDelta(0.004, $cost->output, 1e-12);
        $this->assertEqualsWithDelta(0.0001, $cost->cached, 1e-12);
        $this->assertEqualsWithDelta(0.0057, $cost->total, 1e-12);
    }

    public function test_falls_back_to_input_rate_when_no_cache_rate(): void
    {
        $calc = new CostCalculator;
        $price = new ModelPrice(model: 'm', inputPerToken: 0.000001, outputPerToken: 0.0);
        $tokens = new TokenUsage(input: 100, output: 0, cached: 40);

        // cached billed at input rate when no cache rate: 100 * 1e-6 total input
        $cost = $calc->cost($tokens, $price);

        $this->assertEqualsWithDelta(0.0001, $cost->input, 1e-12);
        $this->assertEqualsWithDelta(0.00004, $cost->cached, 1e-12);
    }

    public function test_null_price_yields_zero_in_fallback_currency(): void
    {
        $cost = (new CostCalculator)->cost(new TokenUsage(input: 10, output: 10), null, 'EUR');

        $this->assertSame(0.0, $cost->total);
        $this->assertSame('EUR', $cost->currency);
    }
}

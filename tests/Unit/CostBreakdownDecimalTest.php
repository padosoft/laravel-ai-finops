<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

/**
 * v1.3 — money is exposed as fixed-precision 8-dp decimal STRINGS (the
 * authoritative, drift-free representation) alongside the back-compatible float
 * fields.
 */
class CostBreakdownDecimalTest extends TestCase
{
    public function test_decimal_accessors_are_fixed_precision_strings(): void
    {
        $c = new CostBreakdown(total: 0.000241, input: 0.0001, output: 0.000141, cached: 0.0, currency: 'USD');

        $this->assertSame('0.00024100', $c->totalDecimal());
        $this->assertSame('0.00010000', $c->inputDecimal());
        $this->assertSame('0.00014100', $c->outputDecimal());
        $this->assertSame('0.00000000', $c->cachedDecimal());
    }

    public function test_to_array_adds_decimal_strings_and_keeps_floats(): void
    {
        $arr = (new CostBreakdown(total: 1.5, input: 1.0, output: 0.5, cached: 0.0, currency: 'EUR'))->toArray();

        // Additive decimal keys (strings).
        $this->assertSame('1.50000000', $arr['total_decimal']);
        $this->assertSame('1.00000000', $arr['input_decimal']);
        $this->assertSame('0.50000000', $arr['output_decimal']);
        $this->assertSame('0.00000000', $arr['cached_decimal']);
        $this->assertIsString($arr['total_decimal']);

        // Back-compatible float keys + currency kept.
        $this->assertSame(1.5, $arr['total']);
        $this->assertIsFloat($arr['total']);
        $this->assertSame('EUR', $arr['currency']);
    }

    public function test_decimal_helper_uses_dot_and_no_thousands_separator(): void
    {
        $this->assertSame('1234567.00000000', CostBreakdown::decimal(1234567.0));
        $this->assertSame('0.00000000', CostBreakdown::decimal(0.0));
        $this->assertSame(CostBreakdown::SCALE, 8);
    }
}

<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Pricing\CostCalculator;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class OverheadOverlayTest extends TestCase
{
    public function test_overhead_applies_for_configured_provider(): void
    {
        config(['ai-finops.pricing.fees' => ['openrouter' => ['markup_pct' => 5.5]]]);

        $calc = new CostCalculator;

        $this->assertEqualsWithDelta(105.5, $calc->withOverhead(100.0, 'openrouter'), 1e-9);
    }

    public function test_no_overhead_for_unconfigured_provider(): void
    {
        config(['ai-finops.pricing.fees' => ['openrouter' => ['markup_pct' => 5.5]]]);

        $calc = new CostCalculator;

        $this->assertEqualsWithDelta(100.0, $calc->withOverhead(100.0, 'openai'), 1e-9);
    }

    public function test_no_overhead_when_provider_null(): void
    {
        config(['ai-finops.pricing.fees' => ['openrouter' => ['markup_pct' => 5.5]]]);

        $calc = new CostCalculator;

        $this->assertEqualsWithDelta(100.0, $calc->withOverhead(100.0, null), 1e-9);
    }

    public function test_zero_or_missing_pct_is_noop(): void
    {
        config(['ai-finops.pricing.fees' => []]);

        $calc = new CostCalculator;

        $this->assertEqualsWithDelta(42.0, $calc->withOverhead(42.0, 'openrouter'), 1e-9);
    }
}

<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\ManualPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class ManualPricingSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_million_eur_override_normalizes_to_per_token(): void
    {
        PricingOverride::create([
            'model' => 'Llama-3.3-70B-Instruct',
            'provider' => 'regolo',
            'input_cost_per_token' => 0.60,   // entered per-1M EUR
            'output_cost_per_token' => 2.70,  // entered per-1M EUR
            'unit' => 'per_million',
            'currency' => 'EUR',
        ]);

        $price = PricingOverride::query()->where('model', 'Llama-3.3-70B-Instruct')->first()->toModelPrice();

        $this->assertEqualsWithDelta(0.60 / 1_000_000, $price->inputPerToken, 1e-15);
        $this->assertEqualsWithDelta(2.70 / 1_000_000, $price->outputPerToken, 1e-15);
        $this->assertSame('EUR', $price->currency);
        $this->assertSame('override', $price->source);
    }

    public function test_default_unit_is_per_token(): void
    {
        PricingOverride::create([
            'model' => 'gpt-5.1',
            'input_cost_per_token' => 0.000002,
            'output_cost_per_token' => 0.000008,
        ]);

        $price = PricingOverride::query()->where('model', 'gpt-5.1')->first()->toModelPrice();

        $this->assertEqualsWithDelta(0.000002, $price->inputPerToken, 1e-15);
    }

    public function test_source_lists_overrides_as_attr_map(): void
    {
        PricingOverride::create([
            'model' => 'Llama-3.3-70B-Instruct',
            'provider' => 'regolo',
            'input_cost_per_token' => 0.60,
            'output_cost_per_token' => 2.70,
            'unit' => 'per_million',
            'currency' => 'EUR',
        ]);

        $source = $this->app->make(ManualPricingSource::class);

        $this->assertSame('manual', $source->name());
        $this->assertSame(1, $source->sync());

        $all = $source->all();
        $this->assertArrayHasKey('Llama-3.3-70B-Instruct', $all);
        $attr = $all['Llama-3.3-70B-Instruct'];
        $this->assertEqualsWithDelta(0.60 / 1_000_000, $attr['input_cost_per_token'], 1e-15);
        $this->assertSame('regolo', $attr['litellm_provider']);
        $this->assertSame('EUR', $attr['currency']);
        $this->assertInstanceOf(\DateTimeInterface::class, $source->syncedAt());
    }

    public function test_source_empty_when_no_overrides(): void
    {
        $source = $this->app->make(ManualPricingSource::class);
        $this->assertSame([], $source->all());
        $this->assertNull($source->syncedAt());
    }
}

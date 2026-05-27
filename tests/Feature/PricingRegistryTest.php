<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PricingRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): PricingRegistry
    {
        $source = new ArrayPricingSource([
            'gpt-x' => [
                'input_cost_per_token' => 0.000002,
                'output_cost_per_token' => 0.000008,
                'litellm_provider' => 'openai',
            ],
        ]);

        return new PricingRegistry($source, $this->app['config']);
    }

    public function test_returns_litellm_base_price_when_no_override(): void
    {
        $price = $this->registry()->priceFor('gpt-x');

        $this->assertNotNull($price);
        $this->assertSame('litellm', $price->source);
        $this->assertSame(0.000002, $price->inputPerToken);
        $this->assertSame('openai', $price->provider);
    }

    public function test_override_wins_over_base_when_overrides_win(): void
    {
        PricingOverride::create([
            'model' => 'gpt-x',
            'provider' => null,
            'input_cost_per_token' => 0.000001,
            'output_cost_per_token' => 0.000004,
            'currency' => 'USD',
        ]);

        $price = $this->registry()->priceFor('gpt-x');

        $this->assertSame('override', $price->source);
        $this->assertSame(0.000001, $price->inputPerToken);
    }

    public function test_base_wins_when_overrides_disabled(): void
    {
        config(['ai-finops.pricing.overrides_win' => false]);

        PricingOverride::create([
            'model' => 'gpt-x',
            'input_cost_per_token' => 0.000001,
            'output_cost_per_token' => 0.000004,
        ]);

        $price = $this->registry()->priceFor('gpt-x');

        $this->assertSame('litellm', $price->source);
        $this->assertSame(0.000002, $price->inputPerToken);
    }

    public function test_unknown_model_returns_null(): void
    {
        $this->assertNull($this->registry()->priceFor('does-not-exist'));
    }
}

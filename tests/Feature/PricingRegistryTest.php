<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
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

        $manager = new PricingSourceManager(['litellm' => $source], $this->app['config']);

        return new PricingRegistry($manager, $this->app['config']);
    }

    private function registryWith(ArrayPricingSource ...$sources): PricingRegistry
    {
        $keyed = [];
        foreach ($sources as $source) {
            $keyed[$source->name()] = $source;
        }

        return new PricingRegistry(new PricingSourceManager($keyed, $this->app['config']), $this->app['config']);
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

    public function test_provider_source_map_routes_to_named_source(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm', 'openrouter']]);
        config(['ai-finops.pricing.provider_source_map' => ['openrouter' => 'openrouter']]);

        $litellm = new ArrayPricingSource(['shared-model' => ['input_cost_per_token' => 9e-6]], 'litellm');
        $openrouter = new ArrayPricingSource(['shared-model' => ['input_cost_per_token' => 1e-6]], 'openrouter');

        $price = $this->registryWith($litellm, $openrouter)->priceFor('shared-model', 'openrouter');

        $this->assertSame('openrouter', $price->source);
        $this->assertSame(1e-6, $price->inputPerToken);
    }

    public function test_freshest_synced_at_wins_when_unmapped(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm', 'openrouter']]);
        config(['ai-finops.pricing.provider_source_map' => []]);

        $older = new ArrayPricingSource(['m' => ['input_cost_per_token' => 9e-6]], 'litellm', now()->subDays(2));
        $newer = new ArrayPricingSource(['m' => ['input_cost_per_token' => 1e-6]], 'openrouter', now());

        $price = $this->registryWith($older, $newer)->priceFor('m', 'someprovider');

        $this->assertSame('openrouter', $price->source);
        $this->assertSame(1e-6, $price->inputPerToken);
    }

    public function test_default_winner_breaks_tie_when_freshness_unknown(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm', 'openrouter']]);
        config(['ai-finops.pricing.provider_source_map' => []]);
        config(['ai-finops.pricing.default_winner' => ['openrouter', 'litellm']]);

        // Both sources lack a synced_at (null) → default_winner order decides.
        $litellm = new ArrayPricingSource(['m' => ['input_cost_per_token' => 9e-6]], 'litellm');
        $openrouter = new ArrayPricingSource(['m' => ['input_cost_per_token' => 1e-6]], 'openrouter');

        $price = $this->registryWith($litellm, $openrouter)->priceFor('m', 'someprovider');

        $this->assertSame('openrouter', $price->source);
    }

    public function test_override_still_wins_across_sources(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm', 'openrouter']]);

        PricingOverride::create([
            'model' => 'm',
            'provider' => null,
            'input_cost_per_token' => 0.000001,
            'output_cost_per_token' => 0.000004,
        ]);

        $litellm = new ArrayPricingSource(['m' => ['input_cost_per_token' => 9e-6]], 'litellm', now());

        $price = $this->registryWith($litellm)->priceFor('m', 'openai');

        $this->assertSame('override', $price->source);
        $this->assertSame(0.000001, $price->inputPerToken);
    }

    public function test_carries_synced_at_provenance_from_winning_source(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm']]);
        config(['ai-finops.pricing.provider_source_map' => []]);

        $at = now()->subHour();
        $litellm = new ArrayPricingSource(['m' => ['input_cost_per_token' => 2e-6]], 'litellm', $at);

        $price = $this->registryWith($litellm)->priceFor('m', 'openai');

        $this->assertNotNull($price->syncedAt);
        $this->assertSame($at->getTimestamp(), $price->syncedAt->getTimestamp());
    }
}

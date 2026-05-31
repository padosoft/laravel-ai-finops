<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PricingSourceManagerTest extends TestCase
{
    private function manager(): PricingSourceManager
    {
        return new PricingSourceManager([
            'litellm' => new ArrayPricingSource(['gpt-5.1' => ['input_cost_per_token' => 2e-6]], 'litellm'),
            'openrouter' => new ArrayPricingSource(['anthropic/claude' => ['input_cost_per_token' => 3e-6]], 'openrouter'),
            'manual' => new ArrayPricingSource(['regolo-llama' => ['input_cost_per_token' => 6e-7]], 'manual'),
        ], $this->app['config']);
    }

    public function test_sources_respect_enabled_and_order(): void
    {
        config(['ai-finops.pricing.sources' => ['manual', 'litellm']]);

        $names = array_map(fn ($s) => $s->name(), $this->manager()->sources());

        $this->assertSame(['manual', 'litellm'], $names);
    }

    public function test_unknown_source_in_config_is_ignored(): void
    {
        config(['ai-finops.pricing.sources' => ['litellm', 'does-not-exist']]);

        $names = array_map(fn ($s) => $s->name(), $this->manager()->sources());

        $this->assertSame(['litellm'], $names);
    }

    public function test_merged_tags_each_model_with_its_source(): void
    {
        config(['ai-finops.pricing.sources' => ['manual', 'litellm', 'openrouter']]);

        $merged = $this->manager()->merged();

        $this->assertSame('litellm', $merged['gpt-5.1']['_source']);
        $this->assertSame('manual', $merged['regolo-llama']['_source']);
        $this->assertSame('openrouter', $merged['anthropic/claude']['_source']);
    }

    public function test_merged_first_listed_source_wins_on_collision(): void
    {
        config(['ai-finops.pricing.sources' => ['manual', 'litellm']]);

        $manager = new PricingSourceManager([
            'litellm' => new ArrayPricingSource(['shared' => ['input_cost_per_token' => 9.0]], 'litellm'),
            'manual' => new ArrayPricingSource(['shared' => ['input_cost_per_token' => 1.0]], 'manual'),
        ], $this->app['config']);

        $merged = $manager->merged();

        $this->assertSame('manual', $merged['shared']['_source']);
        $this->assertSame(1.0, $merged['shared']['input_cost_per_token']);
    }

    public function test_sync_all_returns_per_source_counts(): void
    {
        config(['ai-finops.pricing.sources' => ['manual', 'litellm', 'openrouter']]);

        $counts = $this->manager()->syncAll();

        $this->assertSame(['manual' => 1, 'litellm' => 1, 'openrouter' => 1], $counts);
    }
}

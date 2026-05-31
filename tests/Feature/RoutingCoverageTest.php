<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\SubscriptionWindow;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Pricing\PricingSourceManager;
use Padosoft\LaravelAiFinOps\Routing\NullQualityScoreProvider;
use Padosoft\LaravelAiFinOps\Routing\RoutingEngine;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class RoutingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): RoutingEngine
    {
        $source = new ArrayPricingSource([
            'claude-opus-4' => ['input_cost_per_token' => 0.000015, 'output_cost_per_token' => 0.000075],
            'claude-haiku-4' => ['input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000004],
        ], 'litellm', now());

        $registry = new PricingRegistry(new PricingSourceManager(['litellm' => $source], $this->app['config']), $this->app['config']);

        return new RoutingEngine($registry, new NullQualityScoreProvider);
    }

    public function test_covered_provider_models_have_zero_cost_metric(): void
    {
        SubscriptionWindow::create([
            'provider' => 'anthropic',
            'label' => 'claude-max',
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'enabled' => true,
        ]);

        $result = $this->engine()->recommend(['claude-opus-4', 'claude-haiku-4'], null, 'anthropic');

        foreach ($result['candidates'] as $candidate) {
            $this->assertSame(0.0, $candidate['cost_metric']);
            $this->assertTrue($candidate['covered']);
        }

        $this->assertContains($result['recommended'], ['claude-opus-4', 'claude-haiku-4']);
    }

    public function test_without_window_cost_metric_reflects_price(): void
    {
        $result = $this->engine()->recommend(['claude-opus-4', 'claude-haiku-4'], null, 'anthropic');

        // No coverage → cheapest (haiku) wins and nothing is flagged covered.
        $this->assertSame('claude-haiku-4', $result['recommended']);
        foreach ($result['candidates'] as $candidate) {
            $this->assertFalse($candidate['covered']);
            $this->assertGreaterThan(0.0, $candidate['cost_metric']);
        }
    }
}

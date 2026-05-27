<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Models\PriceWatchSubscription;
use Padosoft\LaravelAiFinOps\Models\PricingOverride;
use Padosoft\LaravelAiFinOps\PriceWatch\PriceWatchService;
use Padosoft\LaravelAiFinOps\Pricing\PricingRegistry;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PriceWatchTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PriceWatchService
    {
        // Fresh registry each call so memoization doesn't mask a price change.
        $source = new ArrayPricingSource([
            'gpt-x' => ['input_cost_per_token' => 0.000002, 'output_cost_per_token' => 0.000008],
        ]);

        return new PriceWatchService(new PricingRegistry($source, $this->app['config']));
    }

    public function test_capture_then_change_is_detected(): void
    {
        PriceWatchSubscription::create(['model' => 'gpt-x']);

        // First snapshot at the LiteLLM base price.
        $this->assertSame(1, $this->service()->capture());

        // Provider "drops" the price → local override wins, second snapshot differs.
        PricingOverride::create(['model' => 'gpt-x', 'input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000008]);
        $this->assertSame(1, $this->service()->capture());

        $changes = $this->service()->changes();

        $this->assertCount(1, $changes);
        $this->assertSame('gpt-x', $changes[0]['model']);
        $this->assertEqualsWithDelta(-50.0, $changes[0]['input_change_pct'], 0.001);
    }

    public function test_subscription_api_and_changes_endpoint(): void
    {
        $this->postJson('/api/ai-finops/price-watch/subscriptions', ['model' => 'gpt-x'])->assertCreated();
        $this->assertSame(1, PriceWatchSubscription::query()->count());

        $this->getJson('/api/ai-finops/price-watch/subscriptions')->assertOk()->assertJsonPath('data.0.model', 'gpt-x');
        $this->getJson('/api/ai-finops/price-watch/changes')->assertOk()->assertJsonStructure(['data']);

        $id = PriceWatchSubscription::query()->firstOrFail()->id;
        $this->deleteJson("/api/ai-finops/price-watch/subscriptions/{$id}")->assertOk();
    }
}

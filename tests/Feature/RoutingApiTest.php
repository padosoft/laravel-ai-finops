<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;
use Padosoft\LaravelAiFinOps\Models\RoutingRule;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class RoutingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource([
            'gpt-5.1' => ['input_cost_per_token' => 0.000010, 'output_cost_per_token' => 0.000030],
            'gpt-5.1-mini' => ['input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000003],
        ]));
    }

    public function test_simulate_picks_cheapest_when_quality_unknown(): void
    {
        $this->postJson('/api/ai-finops/routing/simulate', [
            'candidates' => ['gpt-5.1', 'gpt-5.1-mini'],
        ])
            ->assertOk()
            ->assertJsonPath('recommended', 'gpt-5.1-mini');
    }

    public function test_quality_scores_reports_disabled_by_default(): void
    {
        // Default NullQualityScoreProvider yields no scores.
        $this->assertInstanceOf(QualityScoreProvider::class, $this->app->make(QualityScoreProvider::class));

        $this->getJson('/api/ai-finops/routing/quality-scores')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('scores', []);
    }

    public function test_rule_crud(): void
    {
        $this->postJson('/api/ai-finops/routing/rules', [
            'name' => 'free tier', 'scope_type' => 'tenant', 'scope_id' => 'free',
            'candidates' => ['gpt-5.1-mini', 'gpt-5.1'], 'min_quality' => 0.7,
        ])->assertCreated();

        $id = RoutingRule::query()->firstOrFail()->id;
        $this->getJson('/api/ai-finops/routing/rules')->assertOk()->assertJsonPath('data.0.name', 'free tier');
        $this->deleteJson("/api/ai-finops/routing/rules/{$id}")->assertOk();
    }
}

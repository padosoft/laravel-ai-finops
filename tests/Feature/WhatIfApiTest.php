<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\PricingSource;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Models\WhatIfScenario;
use Padosoft\LaravelAiFinOps\Tests\Support\ArrayPricingSource;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class WhatIfApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PricingSource::class, fn () => new ArrayPricingSource([
            'gpt-5.1-mini' => ['input_cost_per_token' => 0.000001, 'output_cost_per_token' => 0.000003],
        ]));
    }

    private function seedRow(): void
    {
        // Cost actually charged on gpt-5.1 (expensive): 0.10 for this call.
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true), provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage(input: 1000, output: 1000),
            cost: new CostBreakdown(total: 0.10, currency: 'USD'),
        ))->save();
    }

    public function test_simulate_projects_savings_for_cheaper_model(): void
    {
        $this->seedRow();

        $res = $this->postJson('/api/ai-finops/whatif/simulate', [
            'from_model' => 'gpt-5.1', 'to_model' => 'gpt-5.1-mini',
        ])->assertOk()->assertJsonPath('calls', 1)->assertJsonPath('priced', true);

        // 1000*1e-6 + 1000*3e-6 = 0.004 projected vs 0.10 current → positive savings.
        $this->assertEquals(0.10, $res->json('current_cost'));
        $this->assertEquals(0.004, $res->json('projected_cost'));
        $this->assertGreaterThan(0, $res->json('savings'));
    }

    public function test_scenario_store_and_list(): void
    {
        $this->seedRow();

        $this->postJson('/api/ai-finops/whatif/scenarios', [
            'name' => 'switch to mini', 'from_model' => 'gpt-5.1', 'to_model' => 'gpt-5.1-mini',
        ])->assertCreated();

        $this->assertSame(1, WhatIfScenario::query()->count());
        $this->getJson('/api/ai-finops/whatif/scenarios')->assertOk()->assertJsonPath('data.0.name', 'switch to mini');
    }
}

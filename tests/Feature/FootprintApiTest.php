<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class FootprintApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_estimates_energy_and_co2(): void
    {
        config(['ai-finops.footprint.kwh_per_1k_tokens' => 0.001, 'ai-finops.footprint.grid_gco2_per_kwh' => 400]);

        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 't', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage(input: 600, output: 400), // 1000 tokens
            cost: new CostBreakdown(total: 0.01, currency: 'USD'),
        ))->save();

        // 1000/1000 * 0.001 = 0.001 kWh; * 400 = 0.4 gCO2
        $this->getJson('/api/ai-finops/footprint/summary')
            ->assertOk()
            ->assertJsonPath('tokens', 1000)
            ->assertJsonPath('energy_kwh', 0.001)
            ->assertJsonPath('co2_grams', 0.4);
    }

    public function test_trend_returns_daily_series(): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 't', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage(input: 100, output: 100), cost: new CostBreakdown(total: 0.01, currency: 'USD'),
        ))->save();

        $this->getJson('/api/ai-finops/footprint/trend')
            ->assertOk()
            ->assertJsonStructure(['data' => [['day', 'tokens', 'energy_kwh', 'co2_grams']]]);
    }
}

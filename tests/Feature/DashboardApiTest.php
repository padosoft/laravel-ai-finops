<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedRecord(string $provider, string $model, float $cost, ?string $tenant = null, ?string $grantId = null): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true),
            provider: $provider,
            model: $model,
            tokens: new TokenUsage(input: 100, output: 40),
            cost: new CostBreakdown(total: $cost, currency: 'USD'),
            tenantId: $tenant,
            delegationGrantId: $grantId,
        ))->save();
    }

    public function test_kpis_aggregate_the_ledger(): void
    {
        $this->seedRecord('openai', 'gpt-5.1', 0.05);
        $this->seedRecord('anthropic', 'claude-haiku-4.5', 0.03);

        $this->getJson('/api/ai-finops/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('calls_total', 2)
            ->assertJsonPath('cost_total', 0.08)
            ->assertJsonPath('models_count', 2);
    }

    public function test_top_models_ranked_by_cost(): void
    {
        $this->seedRecord('openai', 'gpt-5.1', 0.05);
        $this->seedRecord('openai', 'gpt-5.1', 0.05);
        $this->seedRecord('anthropic', 'claude-haiku-4.5', 0.03);

        $this->getJson('/api/ai-finops/dashboard/top-models')
            ->assertOk()
            ->assertJsonPath('data.0.model', 'gpt-5.1')
            ->assertJsonPath('data.0.calls', 2);
    }

    public function test_top_tenants_excludes_null_tenant(): void
    {
        $this->seedRecord('openai', 'gpt-5.1', 0.05, 'acme');
        $this->seedRecord('openai', 'gpt-5.1', 0.02);

        $this->getJson('/api/ai-finops/dashboard/top-tenants')
            ->assertOk()
            ->assertJsonPath('data.0.tenant_id', 'acme')
            ->assertJsonCount(1, 'data');
    }

    public function test_top_delegations_pivots_delegated_spend_per_grant(): void
    {
        $this->seedRecord('openai', 'gpt-5.1', 0.30, grantId: 'dgr_busy');
        $this->seedRecord('openai', 'gpt-5.1', 0.20, grantId: 'dgr_busy');
        $this->seedRecord('openai', 'gpt-5.1', 0.10, grantId: 'dgr_calm');
        $this->seedRecord('openai', 'gpt-5.1', 9.99); // non-delegated: excluded

        $this->getJson('/api/ai-finops/dashboard/top-delegations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.delegation_grant_id', 'dgr_busy')
            ->assertJsonPath('data.0.calls', 2)
            ->assertJsonPath('data.1.delegation_grant_id', 'dgr_calm');
    }

    public function test_spend_trend_returns_daily_series(): void
    {
        $this->seedRecord('openai', 'gpt-5.1', 0.05);

        $this->getJson('/api/ai-finops/dashboard/spend-trend')
            ->assertOk()
            ->assertJsonStructure(['data' => [['day', 'cost', 'calls']]]);
    }
}

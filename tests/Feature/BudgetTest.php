<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Budgets\BudgetResolver;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(string $tenant, float $cost): void
    {
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true),
            provider: 'openai',
            model: 'gpt-5.1',
            tokens: new TokenUsage(input: 10, output: 5),
            cost: new CostBreakdown(total: $cost, currency: 'USD'),
            tenantId: $tenant,
        ))->save();
    }

    public function test_budget_spend_is_scoped_and_period_bounded(): void
    {
        $this->ledger('acme', 3.0);
        $this->ledger('acme', 2.0);
        $this->ledger('other', 10.0);

        // an old row outside the monthly window must not count
        $old = UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'old', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: 99.0, currency: 'USD'), tenantId: 'acme',
        ));
        $old->save();
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        $budget = Budget::create([
            'name' => 'Acme monthly', 'scope_type' => 'tenant', 'scope_id' => 'acme',
            'limit_amount' => 10.0, 'currency' => 'USD', 'period' => 'monthly', 'soft_limit_pct' => 40,
        ]);

        $status = $budget->status();

        $this->assertSame(5.0, $status->spent);
        $this->assertSame(50.0, $status->percent());
        $this->assertSame('warning', $status->state($budget->soft_limit_pct));
    }

    public function test_resolver_matches_global_and_scoped_budgets(): void
    {
        Budget::create(['name' => 'Global', 'scope_type' => 'global', 'limit_amount' => 100, 'period' => 'monthly']);
        Budget::create(['name' => 'Acme', 'scope_type' => 'tenant', 'scope_id' => 'acme', 'limit_amount' => 10, 'period' => 'monthly']);
        Budget::create(['name' => 'Other', 'scope_type' => 'tenant', 'scope_id' => 'other', 'limit_amount' => 10, 'period' => 'monthly']);

        $envelope = new AiCallEnvelope(traceId: 't', provider: 'openai', model: 'gpt-5.1', tenantId: 'acme');

        $names = $this->app->make(BudgetResolver::class)->applicableTo($envelope)->pluck('name')->all();

        $this->assertContains('Global', $names);
        $this->assertContains('Acme', $names);
        $this->assertNotContains('Other', $names);
    }
}

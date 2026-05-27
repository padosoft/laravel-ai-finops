<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\PolicyAction;
use Padosoft\LaravelAiFinOps\Exceptions\BudgetExceededException;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Policies\EnforcementListener;
use Padosoft\LaravelAiFinOps\Policies\PolicyEngine;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class PolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    private function envelope(string $provider = 'openai', ?string $tenant = null): AiCallEnvelope
    {
        return new AiCallEnvelope(traceId: 't', provider: $provider, model: 'gpt-5.1', tenantId: $tenant);
    }

    private function engine(): PolicyEngine
    {
        return $this->app->make(PolicyEngine::class);
    }

    public function test_global_config_kill_switch_blocks(): void
    {
        config(['ai-finops.kill_switch' => true]);

        $this->assertSame(PolicyAction::Block, $this->engine()->evaluate($this->envelope())->action);
    }

    public function test_scoped_provider_kill_switch_blocks_only_that_provider(): void
    {
        KillSwitch::create(['scope_type' => 'provider', 'scope_id' => 'openai', 'active' => true]);

        $this->assertTrue($this->engine()->evaluate($this->envelope('openai'))->blocked());
        $this->assertFalse($this->engine()->evaluate($this->envelope('anthropic'))->blocked());
    }

    public function test_hard_budget_exceeded_blocks(): void
    {
        Budget::create(['name' => 'Global', 'scope_type' => 'global', 'limit_amount' => 1.0, 'period' => 'monthly', 'hard' => true]);
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'x', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: 2.0, currency: 'USD'),
        ))->save();

        $decision = $this->engine()->evaluate($this->envelope());

        $this->assertTrue($decision->blocked());
        $this->assertNotNull($decision->budgetId);
    }

    public function test_in_flight_estimated_cost_blocks_before_overspending(): void
    {
        Budget::create(['name' => 'Global', 'scope_type' => 'global', 'limit_amount' => 10.0, 'period' => 'monthly', 'hard' => true]);
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'x', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: 9.9, currency: 'USD'),
        ))->save();

        // Already-spent 9.9 is under the 10 limit, but a $1 in-flight call would exceed it.
        $estimate = new AiCallEnvelope(
            traceId: 't', provider: 'openai', model: 'gpt-5.1',
            cost: new CostBreakdown(total: 1.0, currency: 'USD'),
        );

        $this->assertTrue($this->engine()->evaluate($estimate)->blocked());

        // A tiny in-flight cost still fits.
        $small = new AiCallEnvelope(traceId: 't', provider: 'openai', model: 'gpt-5.1', cost: new CostBreakdown(total: 0.01, currency: 'USD'));
        $this->assertFalse($this->engine()->evaluate($small)->blocked());
    }

    public function test_soft_budget_does_not_block(): void
    {
        Budget::create(['name' => 'Soft', 'scope_type' => 'global', 'limit_amount' => 1.0, 'period' => 'monthly', 'hard' => false]);
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'x', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: 2.0, currency: 'USD'),
        ))->save();

        $this->assertFalse($this->engine()->evaluate($this->envelope())->blocked());
    }

    public function test_enforcement_disabled_skips_budget_block_but_keeps_kill_switch(): void
    {
        config(['ai-finops.enforcement' => false]);

        Budget::create(['name' => 'Global', 'scope_type' => 'global', 'limit_amount' => 1.0, 'period' => 'monthly', 'hard' => true]);
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: 'x', provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: 2.0, currency: 'USD'),
        ))->save();

        $this->assertFalse($this->engine()->evaluate($this->envelope())->blocked());

        config(['ai-finops.kill_switch' => true]);
        $this->assertTrue($this->engine()->evaluate($this->envelope())->blocked());
    }

    public function test_enforcement_listener_throws_402_when_blocked(): void
    {
        config(['ai-finops.kill_switch' => true]);

        $this->expectException(BudgetExceededException::class);

        try {
            $this->app->make(EnforcementListener::class)->enforce($this->envelope());
        } catch (BudgetExceededException $e) {
            $this->assertSame(402, $e->getStatusCode());
            throw $e;
        }
    }
}

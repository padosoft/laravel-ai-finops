<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelAiFinOps\Contracts\GuardrailProvider;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\PolicyAction;
use Padosoft\LaravelAiFinOps\Exceptions\BudgetExceededException;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\KillSwitch;
use Padosoft\LaravelAiFinOps\Models\Policy;
use Padosoft\LaravelAiFinOps\Models\SpendApproval;
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

    public function test_policy_require_approval_creates_pending_and_halts(): void
    {
        Policy::create([
            'name' => 'Legal needs approval', 'scope_type' => 'global',
            'min_cost' => 1.0, 'action' => 'require_approval', 'priority' => 10,
        ]);

        $envelope = new AiCallEnvelope(
            traceId: 't', provider: 'openai', model: 'gpt-5.1',
            cost: new CostBreakdown(total: 5.0, currency: 'USD'),
        );

        $decision = $this->engine()->evaluate($envelope);

        $this->assertTrue($decision->requiresApproval());
        $this->assertTrue($decision->halts());
        $this->assertNotNull($decision->approvalId);
        $this->assertSame('pending', SpendApproval::query()->findOrFail($decision->approvalId)->status);
    }

    public function test_policy_below_min_cost_does_not_match(): void
    {
        Policy::create([
            'name' => 'Expensive only', 'scope_type' => 'global',
            'min_cost' => 10.0, 'action' => 'block',
        ]);

        $cheap = new AiCallEnvelope(traceId: 't', provider: 'openai', model: 'gpt-5.1', cost: new CostBreakdown(total: 0.5, currency: 'USD'));

        $this->assertFalse($this->engine()->evaluate($cheap)->blocked());
    }

    public function test_policy_downgrade_is_advisory_not_halting(): void
    {
        Policy::create([
            'name' => 'Cheap tier downgrade', 'scope_type' => 'tenant', 'scope_id' => 'free',
            'action' => 'downgrade', 'action_param' => 'gpt-5.1-mini',
        ]);

        $envelope = new AiCallEnvelope(traceId: 't', provider: 'openai', model: 'gpt-5.1', tenantId: 'free');

        $decision = $this->engine()->evaluate($envelope);

        $this->assertFalse($decision->halts());
        $this->assertSame('gpt-5.1-mini', $decision->suggestedModel);
    }

    public function test_enforcement_off_skips_halting_policies_but_keeps_advisory(): void
    {
        config(['ai-finops.enforcement' => false]);

        Policy::create(['name' => 'block big', 'scope_type' => 'global', 'min_cost' => 1.0, 'action' => 'block', 'priority' => 10]);
        Policy::create(['name' => 'downgrade', 'scope_type' => 'global', 'action' => 'downgrade', 'action_param' => 'gpt-5.1-mini', 'priority' => 20]);

        $envelope = new AiCallEnvelope(traceId: 't', provider: 'openai', model: 'gpt-5.1', cost: new CostBreakdown(total: 5.0, currency: 'USD'));

        $decision = $this->engine()->evaluate($envelope);

        // The block policy is skipped (observability mode); the advisory downgrade is surfaced.
        $this->assertFalse($decision->halts());
        $this->assertSame('gpt-5.1-mini', $decision->suggestedModel);
    }

    public function test_guardrail_violation_blocks_when_feature_enabled(): void
    {
        config(['ai-finops.features.guardrail_linked_spend' => true]);

        $this->app->singleton(GuardrailProvider::class, fn () => new class implements GuardrailProvider
        {
            public function violation(AiCallEnvelope $envelope): ?string
            {
                return 'unredacted PII';
            }
        });

        $decision = $this->engine()->evaluate($this->envelope());

        $this->assertTrue($decision->blocked());
        $this->assertStringContainsString('guardrail', $decision->reason);
    }

    public function test_guardrail_ignored_when_feature_disabled(): void
    {
        config(['ai-finops.features.guardrail_linked_spend' => false]);

        $this->app->singleton(GuardrailProvider::class, fn () => new class implements GuardrailProvider
        {
            public function violation(AiCallEnvelope $envelope): ?string
            {
                return 'unredacted PII';
            }
        });

        $this->assertFalse($this->engine()->evaluate($this->envelope())->blocked());
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

<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Padosoft\LaravelAiFinOps\Alerts\AlertDispatcher;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Events\BudgetThresholdReached;
use Padosoft\LaravelAiFinOps\Models\AlertChannel;
use Padosoft\LaravelAiFinOps\Models\AlertLogEntry;
use Padosoft\LaravelAiFinOps\Models\AlertRule;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    private function budgetAt(float $limit, float $spent): Budget
    {
        $budget = Budget::create(['name' => 'Global', 'scope_type' => 'global', 'limit_amount' => $limit, 'period' => 'monthly']);
        UsageRecord::fromEnvelope(new AiCallEnvelope(
            traceId: uniqid('t', true), provider: 'openai', model: 'gpt-5.1',
            tokens: new TokenUsage, cost: new CostBreakdown(total: $spent, currency: 'USD'),
        ))->save();

        return $budget;
    }

    public function test_threshold_crossing_fires_once_and_logs(): void
    {
        Event::fake([BudgetThresholdReached::class]);

        $budget = $this->budgetAt(10.0, 8.0); // 80%
        AlertRule::create(['name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80]);

        $dispatcher = $this->app->make(AlertDispatcher::class);

        $this->assertSame(1, $dispatcher->evaluate());
        $this->assertSame(0, $dispatcher->evaluate()); // de-duped

        Event::assertDispatchedTimes(BudgetThresholdReached::class, 1);
        $this->assertSame(1, AlertLogEntry::query()->count());
    }

    public function test_below_threshold_rearms_previous_notification(): void
    {
        $budget = $this->budgetAt(100.0, 5.0); // 5%
        $rule = AlertRule::create(['name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80, 'last_notified_pct' => 80]);

        $this->app->make(AlertDispatcher::class)->evaluate();

        $this->assertNull($rule->fresh()->last_notified_pct);
    }

    public function test_check_alerts_command_runs(): void
    {
        $budget = $this->budgetAt(10.0, 9.0);
        AlertRule::create(['name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80]);

        $this->artisan('ai-finops:check-alerts')->assertSuccessful();
        $this->assertSame(1, AlertLogEntry::query()->count());
    }

    public function test_disabled_budget_does_not_alert(): void
    {
        $budget = $this->budgetAt(10.0, 9.0);
        $budget->update(['enabled' => false]);
        AlertRule::create(['name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80]);

        $this->assertSame(0, $this->app->make(AlertDispatcher::class)->evaluate());
        $this->assertSame(0, AlertLogEntry::query()->count());
    }

    public function test_disabled_channel_suppresses_delivery_but_still_logs(): void
    {
        Event::fake([BudgetThresholdReached::class]);

        $budget = $this->budgetAt(10.0, 9.0);
        $channel = AlertChannel::create(['type' => 'webhook', 'name' => 'Ops', 'enabled' => false]);
        AlertRule::create(['name' => '80%', 'budget_id' => $budget->id, 'threshold_pct' => 80, 'channel_id' => $channel->id]);

        $this->app->make(AlertDispatcher::class)->evaluate();

        $this->assertSame(1, AlertLogEntry::query()->count());
        Event::assertDispatched(BudgetThresholdReached::class, fn ($e) => $e->channel === null);
    }
}

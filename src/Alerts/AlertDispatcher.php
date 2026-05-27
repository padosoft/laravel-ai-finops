<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Alerts;

use Illuminate\Contracts\Events\Dispatcher as Events;
use Padosoft\LaravelAiFinOps\Events\BudgetThresholdReached;
use Padosoft\LaravelAiFinOps\Models\AlertChannel;
use Padosoft\LaravelAiFinOps\Models\AlertLogEntry;
use Padosoft\LaravelAiFinOps\Models\AlertRule;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Throwable;

/**
 * Evaluates alert rules against current budget consumption. On crossing a
 * threshold it logs the event and fires BudgetThresholdReached (host delivers).
 * `last_notified_pct` de-dupes until spend drops back below the threshold.
 */
class AlertDispatcher
{
    public function __construct(private readonly Events $events) {}

    public function evaluate(): int
    {
        try {
            $rules = AlertRule::query()->where('enabled', true)->get();
        } catch (Throwable) {
            return 0;
        }

        $triggered = 0;

        foreach ($rules as $rule) {
            $budget = Budget::query()->find($rule->budget_id);
            if ($budget === null) {
                continue;
            }

            $status = $budget->status();
            $pct = $status->percent();

            if ($pct >= $rule->threshold_pct) {
                if ($rule->last_notified_pct === null) {
                    $this->fire($rule, $status, $pct);
                    $rule->update(['last_notified_pct' => $rule->threshold_pct]);
                    $triggered++;
                }
            } elseif ($rule->last_notified_pct !== null) {
                // Spend dropped back below the threshold (e.g. new period): re-arm.
                $rule->update(['last_notified_pct' => null]);
            }
        }

        return $triggered;
    }

    private function fire(AlertRule $rule, $status, float $pct): void
    {
        $channel = $rule->channel_id ? AlertChannel::query()->find($rule->channel_id) : null;

        AlertLogEntry::create([
            'rule_id' => $rule->id,
            'budget_id' => $rule->budget_id,
            'percent' => $pct,
            'spent' => $status->spent,
            'message' => "Budget '{$status->name}' reached {$pct}% (threshold {$rule->threshold_pct}%)",
            'created_at' => now(),
        ]);

        $this->events->dispatch(new BudgetThresholdReached($rule, $status, $channel));
    }
}

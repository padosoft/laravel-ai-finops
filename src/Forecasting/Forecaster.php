<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Forecasting;

use Carbon\CarbonImmutable;
use Padosoft\LaravelAiFinOps\Models\Budget;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;

/**
 * Projects period-end spend with a simple run-rate (spend-so-far / elapsed × total
 * period length). Good enough for "will I blow the budget?" forecasting without ML.
 */
class Forecaster
{
    /**
     * Run-rate forecast for the current calendar month (global, all spend).
     *
     * @return array<string,mixed>
     */
    public function monthToDate(): array
    {
        $now = CarbonImmutable::now();
        $start = $now->startOfMonth();
        $daysElapsed = max(1, $start->diffInDays($now) + 1);
        $daysInMonth = $now->daysInMonth;

        $spent = (float) UsageRecord::query()->where('created_at', '>=', $start)->sum('cost_total');
        $projected = round($spent / $daysElapsed * $daysInMonth, 6);

        return [
            'period' => 'month',
            'period_start' => $start->toDateString(),
            'spent' => round($spent, 6),
            'days_elapsed' => $daysElapsed,
            'days_in_period' => $daysInMonth,
            'projected' => $projected,
            'currency' => config('ai-finops.currency.base', 'USD'),
        ];
    }

    /**
     * Forecast a specific budget's current period and whether/when it will exceed.
     *
     * @return array<string,mixed>
     */
    public function forBudget(Budget $budget): array
    {
        $now = CarbonImmutable::now();
        $start = CarbonImmutable::parse($budget->currentPeriodStart());
        $periodEnd = $this->periodEnd($budget, $start);

        $totalDays = max(1, $start->diffInDays($periodEnd));
        $elapsedDays = max(1, min($totalDays, $start->diffInDays($now) + 1));

        $spent = $budget->spend();
        $limit = (float) $budget->limit_amount;
        $runRate = $spent / $elapsedDays;
        $projected = round($runRate * $totalDays, 6);

        $willExceed = $limit > 0 && $projected > $limit;
        $exceedOn = null;
        if ($willExceed && $runRate > 0) {
            $daysToLimit = (int) ceil($limit / $runRate);
            $exceedOn = $start->addDays($daysToLimit)->toDateString();
        }

        return [
            'budget_id' => (int) $budget->id,
            'limit' => $limit,
            'spent' => round($spent, 6),
            'projected' => $projected,
            'will_exceed' => $willExceed,
            'exceed_on' => $exceedOn,
            'period_start' => $start->toDateString(),
            'currency' => (string) $budget->currency,
        ];
    }

    private function periodEnd(Budget $budget, CarbonImmutable $start): CarbonImmutable
    {
        return match ($budget->period) {
            'daily' => $start->addDay(),
            'weekly' => $start->addWeek(),
            'quarterly' => $start->addQuarter(),
            'yearly' => $start->addYear(),
            'rolling' => $start->addDays(max(1, (int) $budget->rolling_days)),
            default => $start->addMonth(),
        };
    }
}

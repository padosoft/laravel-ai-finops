<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

/** Snapshot of a budget's consumption in its current period. */
final readonly class BudgetStatus
{
    public function __construct(
        public int $budgetId,
        public string $name,
        public float $limit,
        public float $spent,
        public string $currency,
        public bool $hard,
        public string $periodStart,
    ) {}

    public function remaining(): float
    {
        return round($this->limit - $this->spent, 8);
    }

    /** Fixed-precision decimal string (8 dp) of the limit (v1.3, additive). */
    public function limitDecimal(): string
    {
        return CostBreakdown::decimal($this->limit);
    }

    /** Fixed-precision decimal string (8 dp) of the spend (v1.3, additive). */
    public function spentDecimal(): string
    {
        return CostBreakdown::decimal($this->spent);
    }

    /** Fixed-precision decimal string (8 dp) of the remaining budget (v1.3, additive). */
    public function remainingDecimal(): string
    {
        return CostBreakdown::decimal($this->remaining());
    }

    /** A ratio, not money — stays a rounded float. */
    public function percent(): float
    {
        return $this->limit > 0 ? round(($this->spent / $this->limit) * 100, 4) : 0.0;
    }

    public function exceeded(): bool
    {
        return $this->spent >= $this->limit;
    }

    /** @return 'ok'|'warning'|'exceeded' */
    public function state(?int $softLimitPct): string
    {
        if ($this->exceeded()) {
            return 'exceeded';
        }

        if ($softLimitPct !== null && $this->limit > 0 && $this->spent >= ($this->limit * $softLimitPct / 100)) {
            return 'warning';
        }

        return 'ok';
    }

    /** @return array<string,mixed> */
    public function toArray(?int $softLimitPct = null): array
    {
        return [
            'budget_id' => $this->budgetId,
            'name' => $this->name,
            'limit' => $this->limit,
            'spent' => round($this->spent, 8),
            'remaining' => $this->remaining(),
            // v1.3 — authoritative fixed-precision decimal strings (additive).
            'limit_decimal' => $this->limitDecimal(),
            'spent_decimal' => $this->spentDecimal(),
            'remaining_decimal' => $this->remainingDecimal(),
            'percent' => $this->percent(),
            'currency' => $this->currency,
            'hard' => $this->hard,
            'state' => $this->state($softLimitPct),
            'period_start' => $this->periodStart,
        ];
    }
}

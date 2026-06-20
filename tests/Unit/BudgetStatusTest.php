<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Data\BudgetStatus;
use Padosoft\LaravelAiFinOps\Enums\BudgetPeriod;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class BudgetStatusTest extends TestCase
{
    private function makeStatus(float $limit, float $spent): BudgetStatus
    {
        return new BudgetStatus(1, 'b', $limit, $spent, 'USD', true, '2026-05-01 00:00:00');
    }

    public function test_remaining_and_percent(): void
    {
        $s = $this->makeStatus(100.0, 25.0);

        $this->assertSame(75.0, $s->remaining());
        $this->assertSame(25.0, $s->percent());
    }

    public function test_fixed_precision_decimal_strings(): void
    {
        $s = $this->makeStatus(100.0, 25.5);

        // v1.3 — money exposed as fixed-precision 8-dp decimal STRINGS.
        $this->assertSame('100.00000000', $s->limitDecimal());
        $this->assertSame('25.50000000', $s->spentDecimal());
        $this->assertSame('74.50000000', $s->remainingDecimal());

        $arr = $s->toArray();
        // Additive: the decimal keys are strings; the float keys are kept.
        $this->assertSame('100.00000000', $arr['limit_decimal']);
        $this->assertSame('25.50000000', $arr['spent_decimal']);
        $this->assertSame('74.50000000', $arr['remaining_decimal']);
        $this->assertIsString($arr['limit_decimal']);
        $this->assertSame(100.0, $arr['limit']); // back-compat float kept
        $this->assertIsFloat($arr['percent']);    // ratio stays a float
    }

    public function test_state_transitions(): void
    {
        $this->assertSame('ok', $this->makeStatus(100, 10)->state(80));
        $this->assertSame('warning', $this->makeStatus(100, 85)->state(80));
        $this->assertSame('exceeded', $this->makeStatus(100, 100)->state(80));
        $this->assertSame('ok', $this->makeStatus(100, 85)->state(null)); // no soft limit
    }

    public function test_rolling_period_start_uses_trailing_days(): void
    {
        $now = new \DateTimeImmutable('2026-05-27 12:00:00');
        $start = BudgetPeriod::Rolling->currentStart($now, 7);

        $this->assertSame('2026-05-20', $start->toDateString());
    }

    public function test_monthly_period_start(): void
    {
        $now = new \DateTimeImmutable('2026-05-27 12:00:00');
        $start = BudgetPeriod::Monthly->currentStart($now);

        $this->assertSame('2026-05-01', $start->toDateString());
    }
}

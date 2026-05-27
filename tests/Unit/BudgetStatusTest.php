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

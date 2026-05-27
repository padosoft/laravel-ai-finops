<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

enum BudgetPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Rolling = 'rolling';

    /**
     * Start of the current window relative to $now. For Rolling, the window is
     * the trailing $rollingDays days.
     */
    public function currentStart(DateTimeInterface $now, int $rollingDays = 30): CarbonImmutable
    {
        $now = CarbonImmutable::parse($now);

        return match ($this) {
            self::Daily => $now->startOfDay(),
            self::Weekly => $now->startOfWeek(),
            self::Monthly => $now->startOfMonth(),
            self::Quarterly => $now->startOfQuarter(),
            self::Yearly => $now->startOfYear(),
            self::Rolling => $now->subDays(max(1, $rollingDays)),
        };
    }
}

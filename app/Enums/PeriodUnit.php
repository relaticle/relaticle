<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum PeriodUnit: string
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case Quarters = 'quarters';
    case Years = 'years';

    public function plus(CarbonImmutable $day, int $amount): CarbonImmutable
    {
        return match ($this) {
            self::Days => $day->addDays($amount),
            self::Weeks => $day->addWeeks($amount),
            self::Months => $day->addMonthsNoOverflow($amount),
            self::Quarters => $day->addMonthsNoOverflow($amount * 3),
            self::Years => $day->addYearsNoOverflow($amount),
        };
    }

    public function minus(CarbonImmutable $day, int $amount): CarbonImmutable
    {
        return $this->plus($day, -$amount);
    }

    public function startOf(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Days => $day->startOfDay(),
            self::Weeks => $day->startOfWeek(CarbonInterface::MONDAY),
            self::Months => $day->startOfMonth(),
            self::Quarters => $day->startOfQuarter(),
            self::Years => $day->startOfYear(),
        };
    }

    public function endOf(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Days => $day->endOfDay(),
            self::Weeks => $day->endOfWeek(CarbonInterface::SUNDAY),
            self::Months => $day->endOfMonth(),
            self::Quarters => $day->endOfQuarter(),
            self::Years => $day->endOfYear(),
        };
    }
}

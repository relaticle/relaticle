<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

/**
 * The calendar the signed-in administrator reads the panel in.
 *
 * Every datetime column is UTC, so anything that groups or filters by a *day*
 * has to decide whose day it means. Table and infolist output already answers
 * that with FilamentTimezone (resolved once in AppServiceProvider); this keeps
 * the analytics and filter layers on the same answer rather than silently
 * falling back to the server's day.
 *
 * Boundaries are handed back already converted to UTC: query bindings are
 * formatted in whatever zone the Carbon instance carries, so a viewer-zone
 * instance would compare a local wall clock against a UTC column.
 */
final readonly class ViewerTime
{
    public static function timezone(): string
    {
        return FilamentTimezone::get();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * Midnight opening the viewer's current day.
     */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * First UTC instant of the viewer's calendar day $date (a `Y-m-d` string).
     */
    public static function startOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->setTimezone('UTC');
    }

    /**
     * Last UTC instant of the viewer's calendar day $date (a `Y-m-d` string).
     */
    public static function endOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->endOfDay()->setTimezone('UTC');
    }

    /**
     * The window covering $days of the viewer's calendar days, the last of them
     * being today so far, optionally shifted $shiftDays calendar days back.
     *
     * Shifting by whole calendar days rather than subtracting a measured
     * interval keeps a comparison window ending at the same wall clock across a
     * DST change, so a partial today never reads as a drop against a full
     * previous day. The two windows do not touch: the tail of the shifted
     * window's last day falls in neither, which is the price of comparing equal
     * elapsed time.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodUtc(int $days, int $shiftDays = 0): array
    {
        $start = self::today()->subDays($days - 1 + $shiftDays);
        $end = self::now()->subDays($shiftDays);

        return [$start->setTimezone('UTC'), $end->setTimezone('UTC')];
    }

    /**
     * Wall-clock stamp for when the numbers on screen were read.
     *
     * Dashboard widgets poll rather than reload, so without this a tab left open
     * overnight looks exactly like one opened a second ago. That is the reading
     * error this caption exists to prevent.
     */
    public static function freshnessCaption(): string
    {
        return 'Updated '.self::now()->format('H:i T').'.';
    }
}

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
}

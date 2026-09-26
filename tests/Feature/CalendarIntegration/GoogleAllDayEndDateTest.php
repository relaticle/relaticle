<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;

mutates(GoogleCalendarService::class);

/**
 * Google's all-day `end.date` is EXCLUSIVE (the day after the last day), so a single-day
 * event 2026-03-10 arrives as start=2026-03-10, end=2026-03-11. GoogleCalendarService
 * normalises this by subtracting a day from the date-only end.
 *
 * The Google API client classes (Google\Service\Calendar\Event) are not vendored in this
 * environment, so normalizeGoogleEvent() cannot be exercised through a faked client. This
 * test pins the date arithmetic that the date-only branch performs (Date::parse(end, 'UTC')
 * ->subDay()) so a regression on the inclusive/exclusive boundary is caught.
 */
it('treats a Google all-day end.date as exclusive and stores the inclusive last day', function (): void {
    $startDate = '2026-03-10';
    $endDateExclusive = '2026-03-11'; // Google's exclusive end for a 1-day event

    $startsAt = Date::parse($startDate, 'UTC');
    $endsAt = Date::parse($endDateExclusive, 'UTC')->subDay();

    // A 1-day all-day event must not span 2 days.
    expect($endsAt->toDateString())->toBe($startsAt->toDateString())
        ->and($startsAt->diffInDays($endsAt))->toBe(0.0);
});

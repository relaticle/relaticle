<?php

declare(strict_types=1);

use Carbon\Carbon;
use Relaticle\ImportWizard\Enums\DateFormat;

it('parses every example it advertises in the format picker', function (DateFormat $format, bool $withTime): void {
    foreach ($format->getExamples($withTime) as $example) {
        expect($format->parse($example, $withTime))
            ->toBeInstanceOf(Carbon::class, "{$format->value} advertises '{$example}' but cannot parse it");
    }
})->with([
    'iso date' => [DateFormat::ISO, false],
    'iso datetime' => [DateFormat::ISO, true],
    'european date' => [DateFormat::EUROPEAN, false],
    'european datetime' => [DateFormat::EUROPEAN, true],
    'american date' => [DateFormat::AMERICAN, false],
    'american datetime' => [DateFormat::AMERICAN, true],
]);

it('rejects a calendar-impossible date instead of rolling it into the next month', function (DateFormat $format, string $cell): void {
    expect($format->parse($cell))->toBeNull();
})->with([
    'european 31 February' => [DateFormat::EUROPEAN, '31/02/2024'],
    'european month 45' => [DateFormat::EUROPEAN, '13/45/2024'],
    'european all nines' => [DateFormat::EUROPEAN, '99/99/9999'],
    'american 31 February' => [DateFormat::AMERICAN, '02/31/2024'],
    'american month 13' => [DateFormat::AMERICAN, '13/01/2024'],
    'iso 31 February' => [DateFormat::ISO, '2024-02-31'],
    'iso month 13' => [DateFormat::ISO, '2024-13-01'],
]);

it('rejects a two-digit year rather than reading it as the first century', function (DateFormat $format, string $cell): void {
    expect($format->parse($cell))->toBeNull();
})->with([
    'european' => [DateFormat::EUROPEAN, '1/1/24'],
    'american' => [DateFormat::AMERICAN, '1/1/24'],
    'iso' => [DateFormat::ISO, '24-01-01'],
]);

it('rejects free text and blanks', function (string $cell): void {
    expect(DateFormat::EUROPEAN->parse($cell))->toBeNull();
})->with(['Q/A', 'invalid-date', 'next tuesday', '', '   ']);

it('reads an ambiguous date according to the chosen convention', function (): void {
    expect(DateFormat::EUROPEAN->parse('3/4/2024')?->format('Y-m-d'))->toBe('2024-04-03')
        ->and(DateFormat::AMERICAN->parse('3/4/2024')?->format('Y-m-d'))->toBe('2024-03-04')
        ->and(DateFormat::ISO->parse('3/4/2024'))->toBeNull();
});

it('zeroes the time a date-only format does not carry', function (): void {
    Carbon::setTestNow('2024-06-01 13:45:59');

    expect(DateFormat::EUROPEAN->parse('15/05/2024')?->format('H:i:s'))->toBe('00:00:00');

    Carbon::setTestNow();
});

it('zeroes the seconds a minute-precision format does not carry', function (): void {
    Carbon::setTestNow('2024-06-01 13:45:59');

    expect(DateFormat::EUROPEAN->parse('15/05/2024 16:00', withTime: true)?->format('H:i:s'))->toBe('16:00:00');

    Carbon::setTestNow();
});

it('reads a naive datetime in the importer timezone and stores it as UTC', function (): void {
    $parsed = DateFormat::ISO->parse('2024-05-15 16:00:00', withTime: true, timezone: 'America/New_York');

    expect($parsed?->format('Y-m-d H:i:s'))->toBe('2024-05-15 20:00:00')
        ->and($parsed?->timezoneName)->toBe('UTC');
});

it('leaves a datetime alone when no timezone is supplied', function (): void {
    expect(DateFormat::ISO->parse('2024-05-15 16:00:00', withTime: true)?->format('Y-m-d H:i:s'))
        ->toBe('2024-05-15 16:00:00');
});

it('ignores the timezone for a date, which has no time of day to convert', function (): void {
    expect(DateFormat::ISO->parse('2024-05-15', timezone: 'America/New_York')?->format('Y-m-d'))
        ->toBe('2024-05-15');
});

it('still parses every ordinary date it parsed before', function (DateFormat $format, string $cell, string $expected): void {
    expect($format->parse($cell)?->format('Y-m-d'))->toBe($expected);
})->with([
    [DateFormat::ISO, '2024-05-15', '2024-05-15'],
    [DateFormat::EUROPEAN, '15/05/2024', '2024-05-15'],
    [DateFormat::EUROPEAN, '15-05-2024', '2024-05-15'],
    [DateFormat::EUROPEAN, '15.05.2024', '2024-05-15'],
    [DateFormat::EUROPEAN, '5/5/2024', '2024-05-05'],
    [DateFormat::AMERICAN, '05/15/2024', '2024-05-15'],
    [DateFormat::AMERICAN, '05-15-2024', '2024-05-15'],
    [DateFormat::AMERICAN, '5/5/2024', '2024-05-05'],
]);

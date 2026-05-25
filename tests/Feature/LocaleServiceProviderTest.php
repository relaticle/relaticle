<?php

declare(strict_types=1);

use App\Providers\LocaleServiceProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;

afterEach(function (): void {
    $defaultLocale = config('app.locale');

    app()->setLocale($defaultLocale);
    Date::setLocale($defaultLocale);
    Number::useLocale($defaultLocale);
});

it('localizes Carbon dates when app locale is set', function (): void {
    app()->setLocale('fr');
    (new LocaleServiceProvider(app()))->boot();

    $formatted = Carbon::parse('2026-05-09')->translatedFormat('F');

    expect($formatted)->toBe('mai');
});

it('localizes Number formatting when app locale is set', function (): void {
    app()->setLocale('fr');
    (new LocaleServiceProvider(app()))->boot();

    // ICU thousands separator for French is U+202F (narrow NBSP) on modern ICU,
    // U+00A0 (NBSP) on older ICU, and a plain space on minimal builds.
    // Assert behaviour rather than exact codepoint to stay portable across CI/runtime variants.
    expect(Number::format(1234.5))->toMatch('/^1[\x{0020}\x{00A0}\x{202F}]234,5$/u');
});

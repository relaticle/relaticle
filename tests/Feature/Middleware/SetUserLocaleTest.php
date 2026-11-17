<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;

mutates(SetUserLocale::class, Locales::class);

beforeEach(function (): void {
    Route::middleware('web')->get('/locale-probe', fn (): string => app()->getLocale());
    Route::middleware('web')->get('/locale-dispatch', function (): string {
        dispatch(fn () => Cache::put('job-locale', app()->getLocale()));

        return 'queued';
    });
});

afterEach(function (): void {
    Locales::apply(config('app.default_locale'));
});

it('renders the panel in the user language', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);
    $workspace = $user->currentWorkspace;

    $this->actingAs($user)
        ->get(Dashboard::getUrl(tenant: $workspace))
        ->assertOk()
        ->assertSeeHtml('lang="de"');
});

it('applies the user language to dates and numbers', function (): void {
    $user = User::factory()->create(['locale' => 'fr']);

    $this->actingAs($user)->get('/locale-probe')->assertSee('fr');

    expect(Date::parse('2026-05-09')->translatedFormat('F'))->toBe('mai');
});

it('uses the cookie then Accept-Language for a guest', function (?string $cookie, ?string $header, string $expected): void {
    $this->withUnencryptedCookies($cookie === null ? [] : [Locales::COOKIE => $cookie])
        ->withHeaders($header === null ? [] : ['Accept-Language' => $header])
        ->get('/locale-probe')
        ->assertSee($expected);
})->with([
    'cookie' => ['es', null, 'es'],
    'cookie beats header' => ['es', 'de', 'es'],
    'unlisted cookie falls to header' => ['pt', 'fr', 'fr'],
    'regional header' => [null, 'de-AT,de;q=0.9', 'de'],
    'unlisted header' => [null, 'pt-BR,pt;q=0.9', 'en'],
    'no hints' => [null, null, 'en'],
]);

it('reads the first listed language of an Accept-Language header by weight', function (): void {
    expect(Locales::fromHeader('pt;q=1.0, fr;q=0.8, de;q=0.9'))->toBe('de')
        ->and(Locales::fromHeader('*'))->toBeNull()
        ->and(Locales::fromHeader(null))->toBeNull();
});

it('carries the language into a queued job and resets it for the next', function (): void {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)->get('/locale-dispatch')->assertSee('queued');

    expect(Cache::get('job-locale'))->toBe('de');

    Context::flush();
    app()->setLocale('fr');
    dispatch(fn () => Cache::put('job-locale', app()->getLocale()));

    expect(Cache::get('job-locale'))->toBe('en')
        ->and(config('app.default_locale'))->toBe('en');
});

it('restores the previous locale after running inside another', function (): void {
    Locales::apply('es');

    $inside = Locales::within('de', fn (): string => app()->getLocale());

    expect($inside)->toBe('de')
        ->and(app()->getLocale())->toBe('es')
        ->and(Date::parse('2026-05-09')->translatedFormat('F'))->toBe('mayo');
});

it('restores the previous locale when the callback throws', function (): void {
    Locales::apply('es');

    try {
        Locales::within('de', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(app()->getLocale())->toBe('es')
        ->and(Number::format(1234.5))->toBe(Number::withLocale('es', fn () => Number::format(1234.5)));
});

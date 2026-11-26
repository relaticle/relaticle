<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationType;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\Locales;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SetUserLocale::class, Locales::class);

final class FailingLocaleJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }

    public function failed(): void
    {
        Cache::put('failed-locale', app()->getLocale());
    }
}

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

it('reads the highest weight per language of an Accept-Language header', function (string $header, ?string $expected): void {
    expect(Locales::fromHeader($header))->toBe($expected);
})->with([
    'later entry of a language outweighs an earlier one' => ['de-CH;q=0.1, fr;q=0.5, de;q=0.9', 'de'],
    'weight above one is ignored' => ['fr;q=5, de;q=0.5', 'de'],
    'negative weight is ignored' => ['fr;q=-1, es;q=0.2', 'es'],
    'zero weight is refused' => ['fr;q=0', null],
]);

it('keeps the admin panel in the install default whatever the visitor sends', function (): void {
    config(['app.sysadmin_domain' => 'admin.relaticle.test']);
    Route::domain('admin.relaticle.test')->middleware('web')->get('/locale-context', fn (): string => app()->getLocale().'|'.var_export(Context::hasHidden('locale'), true));

    $this->withHeaders(['Accept-Language' => 'de'])
        ->get('http://admin.relaticle.test/locale-context')
        ->assertSee('en|false');
});

it('resets to the install default once the request is known to read across workspaces', function (): void {
    Route::middleware('web')->get('/locale-across', fn (): string => app()->getLocale().'|'.var_export(Context::hasHidden('locale'), true));
    app(CurrentWorkspace::class)->readAcrossWorkspaces();

    $this->withHeaders(['Accept-Language' => 'de'])
        ->get('/locale-across')
        ->assertSee('en|false');
});

it('leaves the process locale alone after a synchronous job', function (): void {
    Locales::apply('fr');

    dispatch(fn () => Cache::put('job-locale', app()->getLocale()));

    expect(app()->getLocale())->toBe('fr');
});

it('restores the locale when a synchronous job throws', function (): void {
    Locales::apply('fr');

    try {
        dispatch(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(app()->getLocale())->toBe('fr');
});

it('carries the scoped language into a job dispatched inside within', function (): void {
    $inside = Locales::within('de', function (): string {
        dispatch(fn () => Cache::put('job-locale', app()->getLocale()));

        return app()->getLocale();
    });

    expect($inside)->toBe('de')
        ->and(Cache::get('job-locale'))->toBe('de')
        ->and(Context::hasHidden('locale'))->toBeFalse();
});

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
        ->and(Number::format(1234.5))->toBe(Number::withLocale(Locales::numberLocale('es'), fn () => Number::format(1234.5)));
});

it('labels the panel navigation group in the user language', function (): void {
    app('translator')->addLines(['filament/panel.navigation_groups.tasks' => 'Aufgabengruppe'], 'de');
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    $this->actingAs($user)->get(Dashboard::getUrl(tenant: $user->currentWorkspace))->assertOk();

    expect(Filament::getPanel('app')->getNavigationGroups())->each(
        fn ($group) => $group->getLabel()->toBe('Aufgabengruppe'),
    );
});

it('keeps a path-mounted admin Livewire update in the install default', function (): void {
    config(['app.sysadmin_domain' => null]);
    $admin = SystemAdministrator::factory()->create();
    $this->actingAs($admin, 'sysadmin');

    $page = $this->followingRedirects()->get('/sysadmin')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $page, $match);
    $snapshot = html_entity_decode($match[1]);
    Auth::shouldUse('web');
    app()->forgetScopedInstances();

    $this->withHeaders([
        'X-Livewire' => '1',
        'Referer' => url('/sysadmin'),
        'Accept-Language' => 'de',
    ])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ])->assertOk();

    expect(Context::hasHidden('locale'))->toBeFalse()
        ->and(Number::format(1234.5))->toBe('1,234.5');
});

it('restores the outer job language after a nested synchronous job', function (): void {
    Locales::apply('fr');
    Context::addHidden('locale', 'de');

    dispatch(function (): void {
        dispatch(fn () => null);
        Cache::put('after-inner', app()->getLocale());
    });

    expect(Cache::get('after-inner'))->toBe('de');
});

it('renders the panel script data in the user language', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);
    $this->actingAs($user)
        ->get(Dashboard::getUrl(tenant: $user->currentWorkspace))
        ->assertOk()
        ->assertSee('Spaltengröße ändern', false)
        ->assertDontSee('Resize column', false);
});

it('renders the panel script data in the guest language on a standalone page', function (): void {
    $user = User::factory()->create();
    $url = URL::signedRoute('mail.unsubscribe', ['user' => $user->id, 'type' => NotificationType::TaskDigest->value]);

    $this->withUnencryptedCookies([Locales::COOKIE => 'de'])
        ->get($url)
        ->assertOk()
        ->assertSee('resizeColumnLabel', false)
        ->assertSee('Spaltengr', false)
        ->assertDontSee('Resize column', false);
});

it('serves a public page when a Livewire request carries a malformed snapshot', function (): void {
    $this->withHeaders(['X-Livewire' => '1'])
        ->get('/locale-probe?components[0][snapshot][]=x')
        ->assertOk();
});

it('runs a failed hook of a synchronous job in the language the job ran in', function (): void {
    Locales::apply('fr');
    Context::addHidden('locale', 'de');

    try {
        dispatch(new FailingLocaleJob);
    } catch (RuntimeException) {
    }

    expect(Cache::get('failed-locale'))->toBe('de');
});

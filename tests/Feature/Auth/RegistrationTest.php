<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Rules\IndisposableMailHost;
use App\Support\Email\MailExchangers;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;

mutates(Login::class, IndisposableMailHost::class);

it('flags the session for signup analytics tracking on signup', function (): void {
    $email = 'jane-track-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(session()->get('fathom.track_signup'))->toBeTrue();
});

it('names a user who signs up with a plus address without the tag', function (): void {
    $email = 'jane.doe+trial-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate');

    expect(User::query()->where('email', $email)->sole()->name)->toBe('Jane Doe');
});

it('names a user whose address starts with a plus tag after the whole local part', function (): void {
    $email = '+trial'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', $email)->sole()->name)->toStartWith('+Trial');
});

it('throttles repeated signup submissions on one discovery', function (): void {
    $component = livewire(Login::class)
        ->fillForm(['email' => 'throttle-'.uniqid().'@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    foreach (range(1, 5) as $attempt) {
        $component->fillForm(['password' => 'short'])->call('authenticate');
    }

    $component->fillForm(['password' => 'short'])
        ->call('authenticate')
        ->assertNotified(__('auth.login.signup_throttled_title'));
});

it('creates no account from a throttled signup submission', function (): void {
    $email = 'throttled-'.uniqid().'@gmail.com';

    $component = livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    foreach (range(1, 5) as $attempt) {
        $component->fillForm(['password' => 'short'])->call('authenticate');
    }

    $component->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertNotified(__('auth.login.signup_throttled_title'));

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('renders the signup event once and clears the flag', function (): void {
    $this->app['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);
    session()->put('fathom.track_signup', true);

    $html = view('filament.app.analytics')->render();

    expect($html)->toContain("fathom.trackEvent('signup')")
        ->and(session()->has('fathom.track_signup'))->toBeFalse();

    $again = view('filament.app.analytics')->render();

    expect($again)->not->toContain("trackEvent('signup')");
});

it('keeps panel auth pages out of tenant-slug normalization', function (): void {
    $this->app['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);

    $html = view('filament.app.analytics')->render();

    expect($html)->toContain("'/register'")
        ->and($html)->toContain("'/login'")
        ->and($html)->toContain("'/email-verification'");
});

it('loads Fathom on the sign-in page', function (): void {
    $this->app['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);

    $this->get(url()->getAppUrl('login'))
        ->assertSuccessful()
        ->assertSee('https://cdn.usefathom.com/script.js', escape: false);
});

it('rejects a signup from a disposable email domain', function (): void {
    $email = 'burner-'.uniqid().'@mailinator.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a disposable email in the active locale', function (): void {
    app()->setLocale('fr');

    $email = 'burner-fr-'.uniqid().'@mailinator.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'Les adresses e-mail jetables ne sont pas autorisées.']);
});

it('rejects a signup from a subdomain of a disposable email domain', function (string $email): void {
    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'indisposable']);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'burner@anything.mailinator.com',
    'burner@sub.yopmail.com',
]);

it('never treats a whitelisted domain as disposable, even when the upstream list adds it', function (string $email): void {
    $storagePath = storage_path('framework/testing/disposable-domains-upstream.json');

    File::ensureDirectoryExists(dirname($storagePath));
    File::put($storagePath, (string) json_encode([Str::after($email, '@')]));

    config([
        'disposable-email.storage' => $storagePath,
        'disposable-email.cache.enabled' => false,
    ]);

    app()->forgetInstance('disposable_email.domains');
    DisposableDomains::clearResolvedInstance('disposable_email.domains');

    try {
        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup')
            ->fillForm(['password' => 'Password123!'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
    } finally {
        File::delete($storagePath);
    }

    expect(User::where('email', $email)->exists())->toBeTrue();
})->with([
    'jane-whitelisted@gmail.com',
    'jane-whitelisted@relaticle.com',
]);

it('rejects a signup from a domain only the local disposable list carries', function (): void {
    $storagePath = storage_path('framework/testing/disposable-domains-local.json');
    $localSources = collect(config('disposable-email.sources'))
        ->reject(fn (string $source): bool => Str::startsWith($source, 'https://'))
        ->values()
        ->all();

    config([
        'disposable-email.sources' => $localSources,
        'disposable-email.storage' => $storagePath,
        'disposable-email.cache.enabled' => false,
    ]);

    app()->forgetInstance('disposable_email.domains');
    DisposableDomains::clearResolvedInstance('disposable_email.domains');

    $email = 'setv'.Str::lower(Str::random(10)).'@mailsink.org';

    try {
        $this->artisan('disposable:update')->assertSuccessful();

        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup')
            ->fillForm(['password' => 'Password123!'])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => 'indisposable']);
    } finally {
        File::delete($storagePath);
    }

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a signup whose domain receives mail on a disposable mail host', function (string $mailHost): void {
    $this->app->instance(MailExchangers::class, new MailExchangers(fn (): array => [$mailHost]));

    $email = 'setv'.Str::lower(Str::random(10)).'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => __('validation.indisposable')]);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'mail.neighbours.sh.',
    'MX2.Neighbours.SH',
    'neighbours.sh',
]);

it('creates the account when the mail host only resembles a disposable one', function (): void {
    $this->app->instance(MailExchangers::class, new MailExchangers(fn (): array => ['mail.notneighbours.sh']));

    $email = 'jane-lookalike-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('skips the mail host lookup once the domain has failed its dns check', function (): void {
    $lookups = 0;
    $this->app->instance(MailExchangers::class, new MailExchangers(function () use (&$lookups): array {
        $lookups++;

        return [];
    }));

    $email = 'jane-'.uniqid().'@no-mail-server.invalid';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect($lookups)->toBe(0)
        ->and(User::where('email', $email)->exists())->toBeFalse();
});

it('rejects a signup submitted faster than a human could type', function (): void {
    config(['honeypot.enabled' => true]);
    $this->freezeTime();

    $email = 'bot-instant-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertForbidden()
        ->assertSet('authMethod', null);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('renders the honeypot fields on the login page', function (): void {
    config(['honeypot.enabled' => true]);

    livewire(Login::class)
        ->assertSee('name="my_name', escape: false)
        ->assertSee('name="valid_from"', escape: false);
});

it('does not expose a fortify registration endpoint', function (): void {
    $this->post('/register', [
        'name' => 'Burner User',
        'email' => 'burner-fortify@mailinator.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertStatus(405);

    expect(User::where('email', 'burner-fortify@mailinator.com')->exists())->toBeFalse();
});

it('redirects the bare register path to the login page', function (): void {
    $this->get('/register')->assertRedirect(url()->getAppUrl('login'));
});

it('redirects the old panel register url to the login page', function (): void {
    $this->get(url()->getAppUrl('register'))->assertRedirect(url()->getAppUrl('login'));
});

it('renders the workspace created event once and clears the flag', function (): void {
    $this->app['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);
    session()->put('fathom.track_workspace_created', true);

    $html = view('filament.app.analytics')->render();

    expect($html)->toContain("fathom.trackEvent('workspace_created')")
        ->and(session()->has('fathom.track_workspace_created'))->toBeFalse();

    $again = view('filament.app.analytics')->render();

    expect($again)->not->toContain("trackEvent('workspace_created')");
});

it('renders each conversion event only for its own flag', function (): void {
    $this->app['env'] = 'production';
    config(['services.fathom.site_id' => 'TESTSITE']);
    session()->put('fathom.track_signup', true);

    $html = view('filament.app.analytics')->render();

    expect($html)->toContain("fathom.trackEvent('signup')")
        ->and($html)->not->toContain("trackEvent('workspace_created')");
});

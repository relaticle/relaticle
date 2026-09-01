<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;

mutates(Login::class);

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

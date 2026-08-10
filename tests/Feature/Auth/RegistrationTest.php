<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

mutates(Register::class);

it('registers a new user without creating a team', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-test@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane-test@gmail.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->ownedTeams)->toHaveCount(0)
        ->and($user->personalTeam())->toBeNull();
});

it('flags the session for signup analytics tracking on registration', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-track@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
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

it('rejects registration from a disposable email domain', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Burner User',
            'email' => 'burner@mailinator.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'burner@mailinator.com')->exists())->toBeFalse();
});

it('requires a passing turnstile challenge when a site key is configured', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake()->fail();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'invalid-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response']);

    expect(User::where('email', 'jane-turnstile@gmail.com')->exists())->toBeFalse();
});

it('rejects registration when the turnstile challenge was never solved', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-missing@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response' => 'required']);

    expect(User::where('email', 'jane-turnstile-missing@gmail.com')->exists())->toBeFalse();
});

it('registers successfully when the turnstile challenge passes', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-ok@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => Turnstile::dummy(),
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-turnstile-ok@gmail.com')->exists())->toBeTrue();
});

it('skips the turnstile challenge when no site key is configured', function (): void {
    config(['services.turnstile.key' => null]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-no-turnstile@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-no-turnstile@gmail.com')->exists())->toBeTrue();
});

it('skips the turnstile challenge when the site key is configured but the secret is missing', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => null,
    ]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-no-secret@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-turnstile-no-secret@gmail.com')->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;

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

it('rejects a disposable email in the active locale', function (): void {
    app()->setLocale('fr');

    livewire(Register::class)
        ->fillForm([
            'name' => 'Burner User',
            'email' => 'burner-fr@mailinator.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['email' => 'Les adresses e-mail jetables ne sont pas autorisées.']);
});

it('rejects registration from a subdomain of a disposable email domain', function (string $email): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Burner User',
            'email' => $email,
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
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
        livewire(Register::class)
            ->fillForm([
                'name' => 'Jane Doe',
                'email' => $email,
                'password' => 'Password123!',
                'passwordConfirmation' => 'Password123!',
            ])
            ->call('register')
            ->assertHasNoFormErrors();
    } finally {
        File::delete($storagePath);
    }

    expect(User::where('email', $email)->exists())->toBeTrue();
})->with([
    'jane-whitelisted@gmail.com',
    'jane-whitelisted@relaticle.com',
]);

it('rejects registration when the honeypot field is filled', function (): void {
    config(['honeypot.enabled' => true]);

    $component = livewire(Register::class)
        ->fillForm([
            'name' => 'Bot User',
            'email' => 'bot-honeypot@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ]);

    $this->travel(2)->seconds();

    $component
        ->set('extraFields.my_name', 'I fill every field I see')
        ->call('register')
        ->assertForbidden();

    expect(User::where('email', 'bot-honeypot@gmail.com')->exists())->toBeFalse();
});

it('rejects a registration submitted faster than a human could', function (): void {
    config(['honeypot.enabled' => true]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Fast Bot',
            'email' => 'bot-instant@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertForbidden();

    expect(User::where('email', 'bot-instant@gmail.com')->exists())->toBeFalse();
});

it('registers normally when the honeypot stays empty and enough time has passed', function (): void {
    config(['honeypot.enabled' => true]);

    $component = livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-honeypot-ok@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ]);

    $this->travel(2)->seconds();

    $component
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-honeypot-ok@gmail.com')->exists())->toBeTrue();
});

it('renders the honeypot fields inside the registration page', function (): void {
    config(['honeypot.enabled' => true]);

    livewire(Register::class)
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

it('redirects the bare register path to the panel register page', function (): void {
    $this->get('/register')->assertRedirect(url()->getAppUrl('register'));
});

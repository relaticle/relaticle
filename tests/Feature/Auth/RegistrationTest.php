<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;
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

it('clears the spent turnstile token when another field fails validation', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake();

    User::factory()->create(['email' => 'jane-taken@gmail.com']);

    $component = livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-taken@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => Turnstile::dummy(),
        ])
        ->call('register')
        ->assertHasFormErrors(['email' => 'unique'])
        ->assertHasNoFormErrors(['cf_turnstile_response']);

    expect($component->get('data.cf_turnstile_response'))->toBeNull();
});

it('clears the turnstile token when the challenge itself fails', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Turnstile::fake()->fail();

    $component = livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-reset@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'spent-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response']);

    expect($component->get('data.cf_turnstile_response'))->toBeNull();
});

it('degrades to a readable error when cloudflare siteverify is unreachable', function (callable $stub): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => $stub]);
    Log::spy();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-outage@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-perfectly-good-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response' => __('auth.turnstile.unavailable')]);

    expect(User::where('email', 'jane-turnstile-outage@gmail.com')->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
})->with([
    'server error' => [fn (): PromiseInterface => Http::response('', 500)],
    'connection failure' => [fn (): never => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

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

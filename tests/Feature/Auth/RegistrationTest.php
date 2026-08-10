<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;
use App\Rules\TurnstileChallenge;
use App\Services\TurnstileClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;
use Psr\Http\Message\RequestInterface;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

mutates(Register::class, TurnstileChallenge::class, TurnstileClient::class);

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

it('rejects a siteverify verdict that is not explicitly successful', function (mixed $body): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response($body)]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-siteverify-bypass@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-token-cloudflare-refused',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response' => __('auth.turnstile.failed')]);

    expect(User::where('email', 'jane-siteverify-bypass@gmail.com')->exists())->toBeFalse();
})->with([
    'unsuccessful with an empty error-codes array' => [['success' => false, 'error-codes' => []]],
    'unsuccessful with no error-codes key' => [['success' => false]],
    'no success key at all' => [[]],
    'not json' => ['<html>we are down</html>'],
]);

it('reports the error code cloudflare returned', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response([
        'success' => false,
        'error-codes' => ['timeout-or-duplicate'],
    ])]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-siteverify-replay@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-spent-token',
        ])
        ->call('register')
        ->assertHasFormErrors([
            'cf_turnstile_response' => __('cloudflare-turnstile::errors.timeout-or-duplicate'),
        ]);
});

it('accepts a genuine siteverify success', function (mixed $body): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => Http::response($body)]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-siteverify-ok@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-token-cloudflare-accepted',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane-siteverify-ok@gmail.com')->exists())->toBeTrue();
})->with([
    'with an empty error-codes array' => [['success' => true, 'error-codes' => []]],
    'without an error-codes key' => [['success' => true]],
]);

it('bounds the siteverify call with a connect and a request timeout', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);

    $sentOptions = [];

    Http::globalMiddleware(function (callable $handler) use (&$sentOptions): Closure {
        return function (RequestInterface $request, array $options) use ($handler, &$sentOptions): mixed {
            $sentOptions = $options;

            return $handler($request, $options);
        };
    });
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'error-codes' => []])]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-siteverify-timeout@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-token-cloudflare-accepted',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect($sentOptions['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
        ->and($sentOptions['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
});

it('renders the turnstile challenge prompt in the active locale', function (): void {
    app()->setLocale('fr');
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-fr@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response' => 'Veuillez compléter la vérification de sécurité.']);
});

it('reports a turnstile failure in the active locale', function (callable $stub, string $expected): void {
    app()->setLocale('fr');
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    Http::fake(['challenges.cloudflare.com/*' => $stub]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-turnstile-fr-failure@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
            'cf_turnstile_response' => 'a-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['cf_turnstile_response' => $expected]);
})->with([
    'unsuccessful verdict' => [
        fn (): PromiseInterface => Http::response(['success' => false, 'error-codes' => []]),
        'La vérification a échoué. Veuillez réessayer.',
    ],
    'siteverify unreachable' => [
        fn (): PromiseInterface => Http::response('', 500),
        'La vérification est temporairement indisponible. Veuillez réessayer dans un instant.',
    ],
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

<?php

declare(strict_types=1);

use App\Console\Commands\UpdateTorExitNodesCommand;
use App\Features\SignupChallenge;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Rules\TurnstileChallenge;
use App\Services\TurnstileClient;
use App\Support\Http\TorExitNodes;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Psr\Http\Message\RequestInterface;

mutates(Login::class, TurnstileChallenge::class, TurnstileClient::class, SignupChallenge::class, TorExitNodes::class, UpdateTorExitNodesCommand::class);

function enableSignupChallengeWithoutTurnstile(): void
{
    config(['relaticle.features.signup_challenge' => true]);
    Feature::flushCache();
}

function enableSignupChallenge(): void
{
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);
    enableSignupChallengeWithoutTurnstile();
}

function fakeSiteverify(bool $success, array $errorCodes = []): void
{
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $success, 'error-codes' => $errorCodes])]);
}

function torExitListBody(string ...$exits): string
{
    $relays = collect(range(1, 600))->map(fn (int $relay): string => long2ip(ip2long('10.0.0.0') + $relay));

    return implode("\n", [...$relays, ...$exits]);
}

function startSignup(string $email): Testable
{
    return livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');
}

function completeSignup(string $email): Testable
{
    return startSignup($email)
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate');
}

it('is off by default', function (): void {
    expect(Feature::active(SignupChallenge::class))->toBeFalse()
        ->and(TurnstileChallenge::isEnabled())->toBeFalse();
});

it('requires a solved challenge on the create-account step when enabled', function (): void {
    enableSignupChallenge();
    fakeSiteverify(true);

    $email = 'jane-unsolved-'.uniqid().'@gmail.com';

    startSignup($email)
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response' => 'required']);

    expect(User::where('email', $email)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('creates the account when the challenge passes', function (): void {
    enableSignupChallenge();
    fakeSiteverify(true);

    $email = 'jane-solved-'.uniqid().'@gmail.com';

    startSignup($email)
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-good-token'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['response'] === 'a-good-token'
        && $request['secret'] === 'test-secret-key');
});

it('rejects a token cloudflare refuses', function (mixed $body): void {
    enableSignupChallenge();
    Http::fake(['challenges.cloudflare.com/*' => Http::response($body)]);

    $email = 'jane-refused-'.uniqid().'@gmail.com';

    startSignup($email)
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-bad-token'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response' => __('auth.turnstile.failed')]);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'unsuccessful with codes' => [['success' => false, 'error-codes' => ['invalid-input-response']]],
    'unsuccessful without codes' => [['success' => false]],
    'no success key' => [[]],
    'not json' => ['<html>down</html>'],
]);

it('logs the error codes cloudflare returned for a rejected token', function (): void {
    enableSignupChallenge();
    fakeSiteverify(false, ['invalid-input-response', 'timeout-or-duplicate']);
    Log::spy();

    startSignup('jane-logged-'.uniqid().'@gmail.com')
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-bad-token'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response']);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'rejected')
            && $context['error_codes'] === ['invalid-input-response', 'timeout-or-duplicate']);
});

it('fails closed when siteverify is unreachable', function (callable $stub): void {
    enableSignupChallenge();
    Http::fake(['challenges.cloudflare.com/*' => $stub]);
    Log::spy();

    $email = 'jane-outage-'.uniqid().'@gmail.com';

    startSignup($email)
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-good-token'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response' => __('auth.turnstile.unavailable')]);

    expect(User::where('email', $email)->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
})->with([
    'server error' => [fn (): PromiseInterface => Http::response('', 500)],
    'connection failure' => [fn (): never => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

it('clears the spent token when another field fails validation', function (): void {
    enableSignupChallenge();
    fakeSiteverify(true);

    $email = 'jane-weak-'.uniqid().'@gmail.com';

    $component = startSignup($email)
        ->fillForm(['password' => 'short', 'cf_turnstile_response' => 'a-good-token'])
        ->call('authenticate')
        ->assertHasFormErrors(['password']);

    expect($component->get('data.cf_turnstile_response'))->toBeNull()
        ->and($component->get('data.cf_turnstile_expanded'))->toBeTrue();
});

it('clears the token when the challenge itself fails', function (): void {
    enableSignupChallenge();
    fakeSiteverify(false, ['timeout-or-duplicate']);

    $component = startSignup('jane-spent-'.uniqid().'@gmail.com')
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-spent-token'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response']);

    expect($component->get('data.cf_turnstile_response'))->toBeNull()
        ->and($component->get('data.cf_turnstile_expanded'))->toBeTrue();
});

it('bounds siteverify with a connect and a request timeout', function (): void {
    enableSignupChallenge();

    $sentOptions = [];

    Http::globalMiddleware(function (callable $handler) use (&$sentOptions): Closure {
        return function (RequestInterface $request, array $options) use ($handler, &$sentOptions): mixed {
            $sentOptions = $options;

            return $handler($request, $options);
        };
    });
    fakeSiteverify(true);

    startSignup('jane-timeout-'.uniqid().'@gmail.com')
        ->fillForm(['password' => 'Password123!', 'cf_turnstile_response' => 'a-good-token'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($sentOptions['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
        ->and($sentOptions['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
});

it('reports the challenge prompt in the active locale', function (): void {
    app()->setLocale('fr');
    enableSignupChallenge();
    fakeSiteverify(true);

    startSignup('jane-fr-'.uniqid().'@gmail.com')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['cf_turnstile_response' => 'Veuillez compléter la vérification de sécurité.']);
});

it('never challenges an existing user signing in with a password', function (): void {
    enableSignupChallenge();
    fakeSiteverify(true);

    $user = User::factory()->create(['password' => 'Password123!']);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->assertFormFieldHidden('cf_turnstile_response')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    Http::assertNothingSent();
});

it('skips the challenge when the flag is on but keys are missing', function (): void {
    config(['relaticle.features.signup_challenge' => true]);
    Feature::flushCache();

    $email = 'jane-nokeys-'.uniqid().'@gmail.com';

    startSignup($email)
        ->assertFormFieldHidden('cf_turnstile_response')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('skips the challenge when keys are set but the flag is off', function (): void {
    config([
        'services.turnstile.key' => 'test-site-key',
        'services.turnstile.secret' => 'test-secret-key',
    ]);

    $email = 'jane-flagoff-'.uniqid().'@gmail.com';

    startSignup($email)
        ->assertFormFieldHidden('cf_turnstile_response')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('refuses a signup from a tor exit relay at the email step', function (): void {
    enableSignupChallengeWithoutTurnstile();
    Http::fake([TorExitNodes::SOURCE_URL => Http::response(torExitListBody('127.0.0.1'))]);
    $this->artisan('tor:update-exit-nodes')->assertSuccessful();

    $email = 'jane-tor-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => __('auth.login.signup_over_tor')])
        ->assertSet('authMethod', null);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('refuses a signup whose address joined the tor exit list after the email step', function (): void {
    enableSignupChallengeWithoutTurnstile();
    Http::fake([TorExitNodes::SOURCE_URL => Http::sequence()
        ->push(torExitListBody())
        ->push(torExitListBody('127.0.0.1'))]);
    resolve(TorExitNodes::class)->refresh();

    $email = 'jane-tor-late-'.uniqid().'@gmail.com';
    $signup = startSignup($email);

    resolve(TorExitNodes::class)->refresh();

    $signup->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => __('auth.login.signup_over_tor')])
        ->assertSet('authMethod', null);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('creates the account from an address the tor exit list does not carry', function (): void {
    enableSignupChallengeWithoutTurnstile();
    Http::fake([TorExitNodes::SOURCE_URL => Http::response(torExitListBody())]);
    $this->artisan('tor:update-exit-nodes')->assertSuccessful();

    $email = 'jane-clearnet-'.uniqid().'@gmail.com';

    completeSignup($email)->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('creates the account from a tor exit relay when the flag is off', function (): void {
    Http::fake([TorExitNodes::SOURCE_URL => Http::response(torExitListBody('127.0.0.1'))]);
    resolve(TorExitNodes::class)->refresh();

    $email = 'jane-tor-flagoff-'.uniqid().'@gmail.com';

    completeSignup($email)->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('creates the account before the tor exit list has ever been fetched', function (): void {
    enableSignupChallengeWithoutTurnstile();

    $email = 'jane-nolist-'.uniqid().'@gmail.com';

    completeSignup($email)->assertHasNoFormErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('keeps the previous tor exit list when a refresh fails', function (callable $failure): void {
    enableSignupChallengeWithoutTurnstile();
    Http::fake([TorExitNodes::SOURCE_URL => Http::sequence()
        ->push(torExitListBody('127.0.0.1'))
        ->whenEmpty($failure())]);
    Log::spy();

    $this->artisan('tor:update-exit-nodes')->assertSuccessful();
    $this->artisan('tor:update-exit-nodes')->assertFailed();

    expect(resolve(TorExitNodes::class)->contains('127.0.0.1'))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
})->with([
    'server error' => [fn (): PromiseInterface => Http::response('', 500)],
    'error page' => [fn (): PromiseInterface => Http::response('<html>maintenance</html>')],
]);

it('skips the tor exit list refresh when the flag is off', function (): void {
    Http::fake();

    $this->artisan('tor:update-exit-nodes')->assertSuccessful();

    Http::assertNothingSent();
    expect(resolve(TorExitNodes::class)->contains('127.0.0.1'))->toBeFalse();
});

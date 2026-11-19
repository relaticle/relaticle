<?php

declare(strict_types=1);

use App\Actions\Auth\CompleteAuthentication;
use App\Actions\Auth\VerifyMfa;
use App\Actions\Auth\VerifyTotpCode;
use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

mutates(CompleteAuthentication::class, AuthenticationSession::class, VerifyMfa::class, VerifyTotpCode::class);

test('completing authentication rotates the session id so a fixated session cannot be reused', function (): void {
    $user = User::factory()->withWorkspace()->create();

    session()->put('attacker.planted', 'pre-login value');
    AuthenticationSession::begin($user, AuthMethod::PASSWORD, null);
    $fixated = session()->getId();

    resolve(CompleteAuthentication::class)->execute();

    expect(session()->getId())->not->toBe($fixated)
        ->and(session()->has('attacker.planted'))->toBeFalse()
        ->and(Auth::guard('web')->id())->toBe($user->getKey());
});

test('a pending authentication proof cannot be spent twice', function (): void {
    $user = User::factory()->withWorkspace()->create();

    AuthenticationSession::begin($user, AuthMethod::PASSWORD, null);
    $pending = AuthenticationSession::pending();

    expect(AuthenticationSession::consume($user))->not->toBe([]);

    session()->put('auth.pending', $pending);

    expect(AuthenticationSession::consume($user))->toBe([]);
});

test('an unproven operation grant never authorizes its write', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    AuthenticationSession::startOperation($user, 'add_passkey', null);

    expect(fn () => AuthenticationSession::requireOperation($user, 'add_passkey', null))
        ->toThrow(ValidationException::class);

    expect(fn () => AuthenticationSession::consumeOperation($user, 'add_passkey', null))
        ->toThrow(ValidationException::class);

    AuthenticationSession::proveOperation($user, 'add_passkey', null);
    AuthenticationSession::consumeOperation($user, 'add_passkey', null);

    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

function passMfaChallenge(User $user, string $code): TestResponse
{
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    return test()->post(route('two-factor.login.store'), ['code' => $code]);
}

test('an authenticator code is refused the second time at the sign-in challenge', function (): void {
    $user = User::factory()->withWorkspace()->withConfirmedMfa()->create();
    $code = resolve(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));

    passMfaChallenge($user, $code)->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $this->post(route('logout'));
    $this->assertGuest();
    Cache::flush();

    passMfaChallenge($user, $code)->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a next-step code is accepted once and refused after', function (): void {
    $user = User::factory()->withWorkspace()->withConfirmedMfa()->create();
    $google2fa = resolve(Google2FA::class);
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

    $nextStepCode = $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);

    passMfaChallenge($user, $nextStepCode)->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $this->post(route('logout'));

    passMfaChallenge($user, $nextStepCode)->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('two users with the same code each use it once', function (): void {
    $first = User::factory()->withWorkspace()->withConfirmedMfa()->create();
    $second = User::factory()->withWorkspace()->withConfirmedMfa()->create();
    $code = resolve(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($first->two_factor_secret));

    passMfaChallenge($first, $code)->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($first);
    $this->post(route('logout'));

    passMfaChallenge($second, $code)->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($second);
});

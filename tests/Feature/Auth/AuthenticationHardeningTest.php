<?php

declare(strict_types=1);

use App\Actions\Auth\CompleteAuthentication;
use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

mutates(CompleteAuthentication::class, AuthenticationSession::class);

test('completing authentication rotates the session id so a fixated session cannot be reused', function (): void {
    $user = User::factory()->withTeam()->create();

    session()->put('attacker.planted', 'pre-login value');
    AuthenticationSession::begin($user, AuthMethod::PASSWORD, null, false);
    $fixated = session()->getId();

    resolve(CompleteAuthentication::class)->execute();

    expect(session()->getId())->not->toBe($fixated)
        ->and(session()->has('attacker.planted'))->toBeFalse()
        ->and(Auth::guard('web')->id())->toBe($user->getKey());
});

test('a pending authentication proof cannot be spent twice', function (): void {
    $user = User::factory()->withTeam()->create();

    AuthenticationSession::begin($user, AuthMethod::PASSWORD, null, false);
    $pending = AuthenticationSession::pending();

    expect(AuthenticationSession::consume($user))->not->toBe([]);

    session()->put('auth.pending', $pending);

    expect(AuthenticationSession::consume($user))->toBe([]);
});

test('an unproven operation grant never authorizes its write', function (): void {
    $user = User::factory()->withTeam()->create();
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

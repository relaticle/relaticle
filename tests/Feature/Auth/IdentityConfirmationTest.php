<?php

declare(strict_types=1);

use App\Actions\Auth\CompleteIdentityMfa;
use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\IdentityConfirmationController;
use App\Http\Controllers\Auth\IdentityConfirmationMfaController;
use App\Http\Controllers\Auth\IdentityConfirmationRedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

mutates(
    IdentityConfirmationController::class,
    IdentityConfirmationMfaController::class,
    IdentityConfirmationRedirectController::class,
    CompleteIdentityMfa::class,
    IdentityConfirmation::class,
);

test('the confirm-identity page renders for a user with a password', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('auth.confirm.heading'));
});

test('the confirm-identity page offers a linked provider for a passwordless user', function () {
    $user = User::factory()->withTeam()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->getKey(),
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '12345',
    ]);
    $this->actingAs($user);

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('auth.confirm.continue_with_provider', ['provider' => 'Google']));
});

test('the confirm-identity mfa page renders', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $this->get(route('identity.confirm.mfa'))
        ->assertOk()
        ->assertSee(__('auth.mfa.heading'))
        ->assertSee('autocomplete="one-time-code"', false)
        ->assertDontSee('name="recovery_code"', false);

    $this->get(route('identity.confirm.mfa', ['recovery' => 1]))
        ->assertOk()
        ->assertSee('name="recovery_code"', false);
});

test('redirecting to google for a fresh provider confirmation requests account selection', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $this->get(route('auth.socialite.confirm.redirect', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirectContains('prompt=select_account')
        ->assertRedirectContains('redirect_uri='.urlencode(route('auth.socialite.confirm.callback', ['provider' => SocialiteProvider::GOOGLE->value])));
});

test('redirecting to microsoft for a fresh provider confirmation forces re-authentication', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $this->get(route('auth.socialite.confirm.redirect', ['provider' => SocialiteProvider::MICROSOFT->value]))
        ->assertRedirectContains('prompt=login')
        ->assertRedirectContains('redirect_uri='.urlencode(route('auth.socialite.confirm.callback', ['provider' => SocialiteProvider::MICROSOFT->value])));
});

test('the mfa follow-up rejects a submission with no pending marker', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('identity.confirm.mfa.store'), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('the mfa follow-up completes a pending passkey ceremony with the correct code', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    IdentityConfirmation::markMfaPending($user, null);

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('identity.confirm.mfa.store'), ['code' => $code])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    expect(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('the mfa follow-up accepts a recovery code for a pending passkey ceremony', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    IdentityConfirmation::markMfaPending($user, null);

    $this->post(route('identity.confirm.mfa.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    expect(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('the mfa follow-up rejects an incorrect code for a pending passkey ceremony', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    IdentityConfirmation::markMfaPending($user, null);

    $this->post(route('identity.confirm.mfa.store'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a session that never completed login MFA can still reach the identity-confirmation mfa page', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->get(route('identity.confirm.mfa'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('a session that never completed login MFA can still reach the provider re-authentication redirect', function () {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->get(route('auth.socialite.confirm.redirect', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirectContains('accounts.google.com');

    $this->assertAuthenticatedAs($user);
});

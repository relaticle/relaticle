<?php

declare(strict_types=1);

use App\Actions\Auth\CancelIdentityConfirmation;
use App\Actions\Auth\CompleteIdentityMfa;
use App\Enums\SocialiteProvider;
use App\Filament\Pages\Security;
use App\Http\Controllers\Auth\IdentityConfirmationController;
use App\Http\Controllers\Auth\IdentityConfirmationMfaController;
use App\Http\Controllers\Auth\IdentityConfirmationRedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

mutates(
    CancelIdentityConfirmation::class,
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

test('the confirm-identity page offers the passkey alternative alongside the password form', function () {
    $user = User::factory()->withTeam()->create();
    Passkey::create([
        'user_id' => $user->getKey(),
        'name' => 'Chrome on macOS',
        'credential_id' => 'confirm-identity-credential',
        'credential' => new CredentialRecord(
            'confirm-identity-credential',
            'public-key',
            [],
            'none',
            new EmptyTrustPath,
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key-bytes',
            'relaticle.test',
            0,
        ),
    ]);
    $this->actingAs($user);

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertSee(__('profile.form.password.label'))
        ->assertSee(__('auth.confirm.use_passkey'))
        ->assertSee(__('auth.confirm.passkey_retry'));
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
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    IdentityConfirmation::markMfaPending($user, null);

    $this->get(route('identity.confirm.mfa'))
        ->assertOk()
        ->assertSee(__('auth.confirm.heading'))
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

test('provider confirmation preserves the settings page for a scoped operation', function (): void {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $this->actingAs($user);
    $settingsUrl = Security::getUrl(['tenant' => $user->currentTeam]);
    AuthenticationSession::startOperation($user, 'add_passkey', null);

    $this->from($settingsUrl)
        ->get(route('auth.socialite.confirm.redirect', ['provider' => 'google']))
        ->assertRedirect()
        ->assertSessionHas('url.intended', $settingsUrl);
});

test('provider confirmation rejects an external return location', function (): void {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $this->actingAs($user);
    AuthenticationSession::startOperation($user, 'add_passkey', null);

    $this->from('https://example.org/steal-session')
        ->get(route('auth.socialite.confirm.redirect', ['provider' => 'google']))
        ->assertRedirect()
        ->assertSessionMissing('url.intended');
});

test('the confirmation page explains a cancelled provider confirmation', function (): void {
    $this->actingAs(User::factory()->withTeam()->socialOnly()->create());

    $this->followingRedirects()
        ->get(route('auth.socialite.confirm.callback', ['provider' => 'google']))
        ->assertSee(__('auth.confirm.cancelled'));
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

test('the mfa follow-up completes a pending password confirmation with the correct code', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('identity.confirm.mfa'));

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('identity.confirm.mfa.store'), ['code' => $code])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    expect(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('the mfa follow-up accepts a recovery code for a pending password confirmation', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('identity.confirm.mfa'));

    $this->post(route('identity.confirm.mfa.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
    expect(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('the mfa follow-up rejects an incorrect code for a pending password confirmation', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('identity.confirm.mfa'));

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

test('cancelling identity MFA preserves authentication and revokes its operation', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    $grant = AuthenticationSession::startOperation($user, 'manage_mfa', null);
    IdentityConfirmation::markMfaPending($user, $grant);

    $this->post(route('identity.confirm.mfa.cancel'))
        ->assertRedirect(Security::getUrl(['tenant' => $user->currentTeam], panel: 'app'));

    $this->assertAuthenticatedAs($user);
    expect(AuthenticationSession::pendingOperation())->toBe([])
        ->and(AuthenticationSession::completeFor($user))->toBeTrue()
        ->and(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
    $this->postJson(route('identity.confirm.mfa.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertUnprocessable();
});

test('cancelling identity MFA does not revoke an operation started in another tab', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    $grant = AuthenticationSession::startOperation($user, 'manage_mfa', null);
    IdentityConfirmation::markMfaPending($user, $grant);
    $otherGrant = AuthenticationSession::startOperation($user, 'add_passkey', null);

    $this->post(route('identity.confirm.mfa.cancel'))->assertRedirect();

    expect(AuthenticationSession::pendingOperation()['id'])->toBe($otherGrant)
        ->and(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('identity confirmation waits for an in-flight login code verification before accepting the code', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);
    $lock = Cache::lock('mfa-verify:'.$user->getAuthIdentifier(), 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        expect(session('auth.password_confirmed_at'))->toBeNull();
    } finally {
        $lock->release();
    }

    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

<?php

declare(strict_types=1);

use App\Actions\Auth\AuthenticatePasskey;
use App\Actions\Auth\BeginAuthentication;
use App\Actions\Auth\CancelAuthentication;
use App\Actions\Auth\ConfirmIdentity;
use App\Features\SocialAuth;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\Dashboard;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\PasskeyConfirmationController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\Auth\PasswordSessionController;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Notifications\Auth\VerifyEmail;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

mutates(CancelAuthentication::class, Login::class, PasswordSessionController::class, MfaChallengeController::class);
mutates(AuthenticatePasskey::class, BeginAuthentication::class, PasskeySessionController::class);
mutates(ConfirmIdentity::class, PasskeyConfirmationController::class);

function base64UrlEncodeForPasskeyTest(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * @return array{credentialId: string, storedCredential: array<string, mixed>, payload: array<string, mixed>}
 */
function buildRealPasskeyAssertion(string $rpId, string $origin, string $challenge, ?string $credentialId = null, bool $userVerified = true): array
{
    $credentialId ??= random_bytes(16);

    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $details = openssl_pkey_get_details($key);
    $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $coseKey = MapObject::create()
        ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
        ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
        ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
        ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
        ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

    $userHandle = random_bytes(16);

    $credentialRecord = CredentialRecord::create(
        $credentialId,
        'public-key',
        [],
        'none',
        EmptyTrustPath::create(),
        Uuid::v4(),
        (string) $coseKey,
        $userHandle,
        0,
    );

    $authenticatorData = hash('sha256', $rpId, true).($userVerified ? "\x05" : "\x01")."\x00\x00\x00\x00";

    $clientDataJson = json_encode([
        'type' => 'webauthn.get',
        'challenge' => base64UrlEncodeForPasskeyTest($challenge),
        'origin' => $origin,
    ], JSON_THROW_ON_ERROR);

    openssl_sign($authenticatorData.hash('sha256', $clientDataJson, true), $signature, $key, OPENSSL_ALGO_SHA256);

    return [
        'credentialId' => $credentialId,
        'storedCredential' => json_decode(WebAuthn::toJson($credentialRecord), true, flags: JSON_THROW_ON_ERROR),
        'payload' => [
            'credential' => [
                'id' => base64UrlEncodeForPasskeyTest($credentialId),
                'rawId' => base64UrlEncodeForPasskeyTest($credentialId),
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => base64UrlEncodeForPasskeyTest($clientDataJson),
                    'authenticatorData' => base64UrlEncodeForPasskeyTest($authenticatorData),
                    'signature' => base64UrlEncodeForPasskeyTest($signature),
                ],
            ],
            'remember' => true,
        ],
    ];
}

function storePasskeyAssertionFor(User $user, array $assertion): Passkey
{
    return Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => base64UrlEncodeForPasskeyTest($assertion['credentialId']),
        'credential' => $assertion['storedCredential'],
    ]);
}

beforeEach(function (): void {
    RateLimiter::clear('login-discover-ip:127.0.0.1');
});

test('login screen can be rendered', function () {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(url()->getAppUrl((string) $team->slug));

    $this->assertAuthenticated();
});

test('password login waits for enrolled MFA before authenticating', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
    expect(session('auth.pending.user_id'))->toBe($user->id);
});

test('the Fortify password route completes login without MFA', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ])->assertRedirect(Dashboard::getUrl(['tenant' => $user->currentTeam]));

    $this->assertAuthenticatedAs($user);
});

test('a recovery code completes pending password MFA', function (): void {
    $user = User::factory()->withConfirmedMfa()->withTeam()->create();
    $dashboard = Dashboard::getUrl(['tenant' => $user->currentTeam]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
    $this->get($dashboard)->assertRedirect();

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertRedirect($dashboard);

    $this->assertAuthenticatedAs($user);
});

test('the MFA challenge offers an autofillable code field and a recovery mode', function (): void {
    $user = User::factory()->withConfirmedMfa()->withTeam()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('autocomplete="one-time-code"', false)
        ->assertDontSee('name="recovery_code"', false);

    $this->get(route('two-factor.login', ['recovery' => 1]))
        ->assertOk()
        ->assertSee('name="recovery_code"', false);
});

test('an invalid TOTP cannot complete pending password MFA', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    $this->postJson(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->assertJson(['two_factor' => true]);

    $this->postJson(route('two-factor.login.store'), [
        'code' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->assertGuest('web');
});

test('an expired pending context cannot complete password MFA', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $pending = session('auth.pending');
    $pending['expires_at'] = now()->subSecond()->getTimestamp();
    session()->put('auth.pending', $pending);

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertSessionHasErrors('recovery_code');

    $this->assertGuest('web');
});

test('a used recovery code cannot complete another password MFA challenge', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ]);
    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertSessionHasErrors('recovery_code');

    $this->assertGuest('web');
});

test('a password reset invalidates pending password MFA', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $token = Password::broker('users')->createToken($user);

    livewire(ResetPassword::class, [
        'email' => $user->email,
        'token' => $token,
    ])->fillForm([
        'password' => 'new-secure-password',
        'passwordConfirmation' => 'new-secure-password',
    ])->call('resetPassword')->assertRedirect();

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertSessionHasErrors('recovery_code');

    $this->assertGuest('web');
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('password login rejects an external destination for an account without a workspace', function (): void {
    $user = User::factory()->create();
    session()->put('url.intended', 'https://untrusted.example/collect');

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(Filament::getPanel('app')->getUrl());

    $this->assertAuthenticatedAs($user);
});

test('login email field has autocomplete=username webauthn for conditional mediation', function (): void {
    livewire(Login::class)
        ->assertSeeHtml('autocomplete="username webauthn"');
});

test('the passkey login endpoint is rate limited', function (): void {
    $lastStatus = 200;

    foreach (range(1, 40) as $ignored) {
        $lastStatus = $this->postJson('/passkeys/login', [])->getStatusCode();

        if ($lastStatus === 429) {
            break;
        }
    }

    expect($lastStatus)->toBe(429);
});

test('the passkey confirmation endpoint is rate limited', function (): void {
    $this->actingAs(User::factory()->create());

    $lastStatus = 200;

    foreach (range(1, 40) as $ignored) {
        $lastStatus = $this->postJson('/passkeys/confirm', [])->getStatusCode();

        if ($lastStatus === 429) {
            break;
        }
    }

    expect($lastStatus)->toBe(429);
});

test('passkey login is allowed for active users', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'authorize-active-'.uniqid(),
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});

test('passkey login is allowed for users scheduled for deletion so they reach the cancellation interstitial', function (): void {
    $user = User::factory()->create([
        'scheduled_deletion_at' => now()->subDay(),
    ]);
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'authorize-scheduled-'.uniqid(),
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});

test('a well-formed passkey assertion completes login through the installed verifier', function (): void {
    $user = User::factory()->withTeam()->create();

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.login'), $assertion['payload'])
        ->assertOk()
        ->assertJson(['redirect' => Dashboard::getUrl(['tenant' => $user->currentTeam])]);

    $this->assertAuthenticatedAs($user);
});

test('a verified passkey satisfies enrolled MFA at login', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.login'), $assertion['payload'])
        ->assertOk()
        ->assertJson(['redirect' => Dashboard::getUrl(['tenant' => $user->currentTeam])])
        ->assertSessionMissing('auth.pending');

    $this->assertAuthenticatedAs($user);
    expect(AuthenticationSession::completeFor($user))->toBeTrue();
});

test('a malformed passkey signature is rejected without authenticating', function (): void {
    $user = User::factory()->withTeam()->create();
    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    $passkey = storePasskeyAssertionFor($user, $assertion);
    $assertion['payload']['credential']['response']['signature'] = base64UrlEncodeForPasskeyTest(str_repeat("\0", 64));

    $this->postJson(route('passkey.login'), $assertion['payload'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);

    $this->assertGuest();
    expect($passkey->refresh()->last_used_at)->toBeNull();
});

test('a malformed passkey signature cannot prove a sensitive operation', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    AuthenticationSession::startOperation($user, 'set_password', null);
    $options = $this->getJson(route('passkey.confirm-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    $passkey = storePasskeyAssertionFor($user, $assertion);
    $assertion['payload']['credential']['response']['signature'] = base64UrlEncodeForPasskeyTest(str_repeat("\0", 64));

    $this->postJson(route('passkey.confirm'), $assertion['payload'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);

    expect(AuthenticationSession::pendingOperation()['proven'])->toBeFalse()
        ->and($passkey->refresh()->last_used_at)->toBeNull();
});

test('a passkey without user verification cannot satisfy MFA at login', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge, userVerified: false);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertUnprocessable();

    $this->assertGuest('web');
});

test('password login still requires MFA when a passkey is registered', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    storePasskeyAssertionFor($user, buildRealPasskeyAssertion('relaticle.test', config('fortify.passkeys.allowed_origins')[0], 'registration-challenge'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
});

test('a passkey assertion with the wrong origin fails at the real verifier', function (): void {
    $user = User::factory()->create();

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], 'https://evil.example', (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertUnprocessable();

    $this->assertGuest('web');
});

test('a passkey assertion with a credential id nobody registered fails at the real verifier', function (): void {
    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertUnprocessable();

    $this->assertGuest('web');
});

test('a passkey revoked after options were issued fails at the real verifier', function (): void {
    $user = User::factory()->create();

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    $passkey = storePasskeyAssertionFor($user, $assertion);
    $passkey->delete();

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertUnprocessable();

    $this->assertGuest('web');
});

test('the passkey login endpoint rejects a submission with no prior options request', function (): void {
    $assertion = buildRealPasskeyAssertion('relaticle.test', config('fortify.passkeys.allowed_origins')[0], 'placeholder-challenge');

    $this->postJson(route('passkey.login'), $assertion['payload'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);

    $this->assertGuest('web');
});

test('a well-formed passkey assertion confirms identity through the installed verifier', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $options = $this->getJson(route('passkey.confirm-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.confirm'), $assertion['payload'])
        ->assertOk()
        ->assertJson(['confirmed' => true]);

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a verified passkey satisfies enrolled MFA for a sensitive operation', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    AuthenticationSession::startOperation($user, 'manage_mfa', null);

    $options = $this->getJson(route('passkey.confirm-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.confirm'), $assertion['payload'])
        ->assertOk()
        ->assertJson(['confirmed' => true])
        ->assertSessionHas('auth.password_confirmed_at');

    expect(AuthenticationSession::pendingOperation()['proven'])->toBeTrue();
    expect(IdentityConfirmation::mfaPendingFor($user))->toBeFalse();
});

test('a passkey without user verification cannot confirm a sensitive operation', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    AuthenticationSession::startOperation($user, 'manage_mfa', null);
    $options = $this->getJson(route('passkey.confirm-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge, userVerified: false);
    storePasskeyAssertionFor($user, $assertion);

    $this->postJson(route('passkey.confirm'), $assertion['payload'])
        ->assertUnprocessable()
        ->assertSessionMissing('auth.password_confirmed_at');

    expect(AuthenticationSession::pendingOperation()['proven'])->toBeFalse();
});

test('a passkey confirmation rejects a credential registered to a different user', function (): void {
    $owner = User::factory()->withTeam()->create();
    $confirmingUser = User::factory()->withTeam()->create();
    $this->actingAs($confirmingUser);

    $options = $this->getJson(route('passkey.confirm-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($owner, $assertion);

    $this->postJson(route('passkey.confirm'), $assertion['payload'])
        ->assertUnprocessable();

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->assertAuthenticatedAs($confirmingUser);
});

test('password confirmation still requires MFA when a passkey is registered', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    storePasskeyAssertionFor($user, buildRealPasskeyAssertion('relaticle.test', config('fortify.passkeys.allowed_origins')[0], 'registration-challenge'));
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);
    AuthenticationSession::startOperation($user, 'manage_mfa', null);

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('identity.confirm.mfa'))
        ->assertSessionMissing('auth.password_confirmed_at');

    expect(AuthenticationSession::pendingOperation()['proven'])->toBeFalse();
});

test('a passkey revoked after verification cannot complete authentication', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = buildRealPasskeyAssertion((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);
    storePasskeyAssertionFor($user, $assertion);
    Event::listen(PasskeyVerified::class, function (PasskeyVerified $event): void {
        $event->passkey->delete();
    });

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertUnprocessable();

    $this->assertGuest('web');
});

test('a scheduled-deletion user with enrolled MFA must complete the challenge before reaching the cancellation interstitial', function (): void {
    $user = User::factory()->withConfirmedMfa()->create([
        'scheduled_deletion_at' => now()->subDay(),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    $this->get(Filament::getPanel('app')->getUrl())
        ->assertRedirect(route('filament.app.scheduled-deletion'));
});

test('a restored session with unproved MFA cannot reach the scheduled-deletion interstitial directly', function (): void {
    $user = User::factory()->withConfirmedMfa()->create([
        'scheduled_deletion_at' => now()->subDay(),
    ]);
    $this->actingAs($user);

    $this->get(route('filament.app.scheduled-deletion'))
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
});

test('a restored session with unproved MFA cannot write through the timezone endpoint', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->post(route('filament.app.timezone.sync'), ['timezone' => 'Europe/Paris'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
    expect($user->fresh()->timezone)->not->toBe('Europe/Paris');
});

test('continue with a password account reveals the password field', function (): void {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('continue with an unknown email offers to sign up', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'nobody-'.uniqid().'@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');
});

test('continue with a passkey account dispatches the passkey challenge', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'discover-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey')
        ->assertSet('passkeyUserHasPassword', true)
        ->assertDispatched('passkey-login');
});

test('a passkey user can fall back to their password', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'fallback-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey')
        ->call('usePassword')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('continue with a social-only account switches to the social method', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'social');
});

test('two step password login still authenticates', function (): void {
    $user = User::factory()->withTeam()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('editing the email resets discovery', function (): void {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['email' => 'someone-else-'.uniqid().'@example.com'])
        ->assertSet('authMethod', null);
});

test('re-pressing continue on a passkey account re-dispatches without consuming rate limit', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'repress-'.uniqid(),
        'credential' => [],
    ]);

    $key = 'login-discover:'.mb_strtolower($user->email).'|127.0.0.1';
    RateLimiter::clear($key);

    $component = livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey');

    $attemptsAfterFirstDiscovery = RateLimiter::attempts($key);

    $component
        ->call('authenticate')
        ->assertDispatched('passkey-login');

    expect(RateLimiter::attempts($key))->toBe($attemptsAfterFirstDiscovery);
});

test('a social only account discovery is treated as password when the flag is off', function (): void {
    Feature::define(SocialAuth::class, false);
    Feature::flushCache();

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('discovery is rate limited per email and ip', function (): void {
    $email = 'throttled-'.uniqid().'@example.com';
    $key = 'login-discover:'.mb_strtolower($email).'|127.0.0.1';
    RateLimiter::clear($key);

    for ($i = 0; $i < 5; $i++) {
        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup');
    }

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('a client cannot preset the auth method by writing the locked property directly', function (): void {
    expect(fn () => livewire(Login::class)->set('authMethod', 'junk'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('discovery matches a stored email case-insensitively for passkey users', function (): void {
    $user = User::factory()->create(['email' => 'MixedCase@Example.com']);
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'mixedcase-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => 'mixedcase@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey');
});

test('a legacy github-only account falls back to the password field', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'github',
        'provider_id' => 'gh-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a social-only account with no linked social row falls back to the password field', function (): void {
    $user = User::factory()->socialOnly()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a microsoft-only account falls back to the password field when microsoft is not configured', function (): void {
    config(['services.microsoft.client_id' => null]);

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'microsoft',
        'provider_id' => 'ms-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a microsoft-only account switches to the social method when microsoft is configured', function (): void {
    config(['services.microsoft.client_id' => 'configured-client-id']);

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'microsoft',
        'provider_id' => 'ms-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'social');
});

test('discovery is rate limited per ip across different emails', function (): void {
    $ipKey = 'login-discover-ip:127.0.0.1';
    RateLimiter::clear($ipKey);

    $emails = [];

    for ($i = 0; $i < 20; $i++) {
        $email = 'sweep-'.$i.'-'.uniqid().'@example.com';
        $emails[] = $email;
        RateLimiter::clear('login-discover:'.mb_strtolower($email).'|127.0.0.1');

        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup');
    }

    $blockedEmail = 'sweep-blocked-'.uniqid().'@example.com';
    RateLimiter::clear('login-discover:'.mb_strtolower($blockedEmail).'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $blockedEmail])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('social hint renders after discovering a social-only account', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSee(__('auth.login.social_hint'));
});

test('password fallback link renders only for passkey users with a password', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'fallback-link-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSee(__('auth.login.use_password'));
});

test('login page renders the google button and no passkey button', function (): void {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertSee(__('auth.login.continue_with', ['provider' => 'Google']));
    $response->assertDontSee('Sign in with a passkey');
});

test('microsoft button renders only when configured', function (): void {
    config(['services.microsoft.client_id' => null]);
    $this->get(url()->getAppUrl('login'))->assertDontSee('Microsoft');

    config(['services.microsoft.client_id' => 'test-client']);
    $this->get(url()->getAppUrl('login'))->assertSee('Microsoft');
});

test('signup mode reveals a single password field with a helper text and a sign up label', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'reveal-signup-'.uniqid().'@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSee(__('auth.login.password_helper'))
        ->assertSee(__('auth.login.sign_up'));
});

test('signup guesses a title-cased name from the email local part', function (): void {
    $email = 'jane.doe_smith@gmail.com';
    RateLimiter::clear('login-discover:'.$email.'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $email)->value('name'))->toBe('Jane Doe Smith');
});

test('signup creates a user and sends a verification email', function (): void {
    Notification::fake();

    $email = 'signup-verify-user@gmail.com';
    RateLimiter::clear('login-discover:'.$email.'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', $email)->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->assertAuthenticated();
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('signup with a matching invitation auto-verifies the email and fires Verified', function (): void {
    Event::fake([Verified::class]);
    Notification::fake();

    $team = Team::factory()->create();
    $email = 'Invited-Signup-'.uniqid().'@Gmail.com';
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
    ]);

    $rawToken = $invitation->issueToken();
    $invitation->save();

    session(['url.intended' => route('team-invitations.token.accept', ['token' => $rawToken])]);

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', mb_strtolower($email))->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    Notification::assertNotSentTo($user, VerifyEmail::class);
    Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));

    $this->get(route('team-invitations.token.accept', ['token' => $rawToken]))->assertOk();

    $this->post(route('team-invitations.token.join', ['token' => $rawToken]))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $team]));
});

test('signup with an expired invitation does not auto-verify the email', function (): void {
    Event::fake([Verified::class]);
    Notification::fake();

    $team = Team::factory()->create();
    $email = 'expired-signup-'.uniqid().'@gmail.com';
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
    ]);

    $rawToken = $invitation->issueToken();
    $invitation->save();
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    session(['url.intended' => route('team-invitations.token.accept', ['token' => $rawToken])]);

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', mb_strtolower($email))->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Event::assertNotDispatched(Verified::class);
});

test('a fresh teamless signup lands on tenant registration, not the login page', function (): void {
    $email = 'fresh-signup-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(url()->getAppUrl());

    $this->get(url()->getAppUrl())
        ->assertRedirect(url()->getAppUrl('new'));
});

test('signup rejects a submission caught by the honeypot', function (): void {
    config(['honeypot.enabled' => true]);

    $email = 'bot-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    $component
        ->set('extraFields.my_name', 'I fill every field I see')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertForbidden();

    expect(User::where('email', $email)->exists())->toBeFalse();
});

test('signup registers normally when the honeypot stays empty and enough time has passed', function (): void {
    config(['honeypot.enabled' => true]);

    $email = 'human-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    $component
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

test('discovery rejects a submission caught by the honeypot', function (): void {
    config(['honeypot.enabled' => true]);

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->set('extraFields.my_name', 'I fill every field I see')
        ->fillForm(['email' => 'bot-discover-'.uniqid().'@gmail.com'])
        ->call('authenticate')
        ->assertForbidden();

    expect($component->get('authMethod'))->toBeNull();
});

test('discovery is capped per ip per day independently of the minute window', function (): void {
    $ip = '127.0.0.1';
    RateLimiter::clear('login-discover-ip:'.$ip);

    $dayKey = 'login-discover-ip-day:'.$ip;
    RateLimiter::clear($dayKey);

    for ($i = 0; $i < 300; $i++) {
        RateLimiter::hit($dayKey, 86400);
    }

    livewire(Login::class)
        ->fillForm(['email' => 'day-capped-'.uniqid().'@example.com'])
        ->call('authenticate')
        ->assertHasFormErrors(['email'])
        ->assertSet('authMethod', null);
});

test('signup rejects a duplicate email at submission', function (): void {
    $email = 'dup-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    User::factory()->create(['email' => $email]);

    $component
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', $email)->count())->toBe(1);
});

test('swapping the email after discovery via a wholesale data set does not create a user and still consumes a throttle hit', function (): void {
    $discoveredEmail = 'discover-swap-source-'.uniqid().'@gmail.com';
    $swappedEmail = 'discover-swap-target-'.uniqid().'@gmail.com';

    $swappedKey = 'login-discover:'.mb_strtolower($swappedEmail).'|127.0.0.1';
    RateLimiter::clear($swappedKey);

    $component = livewire(Login::class)
        ->fillForm(['email' => $discoveredEmail])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSet('discoveredEmail', mb_strtolower($discoveredEmail));

    expect(RateLimiter::attempts($swappedKey))->toBe(0);

    $component
        ->set('data', ['email' => $swappedEmail, 'password' => 'Password123!'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSet('discoveredEmail', mb_strtolower($swappedEmail));

    expect(RateLimiter::attempts($swappedKey))->toBe(1)
        ->and(User::where('email', $swappedEmail)->exists())->toBeFalse()
        ->and(User::where('email', $discoveredEmail)->exists())->toBeFalse();

    $this->assertGuest();
});

test('a mixed-case signup is stored lowercase and can log back in with any casing', function (): void {
    $local = 'MixedCase-'.uniqid();
    $email = $local.'@Gmail.com';
    $canonical = mb_strtolower($email);

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $canonical)->exists())->toBeTrue()
        ->and(User::where('email', $email)->exists())->toBeFalse();

    $this->assertAuthenticated();

    auth()->guard('web')->logout();
    $this->app['session']->flush();

    livewire(Login::class)
        ->fillForm(['email' => mb_strtoupper($local).'@GMAIL.COM'])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('a legacy mixed-case account can log in with a lowercase-typed email after normalization', function (): void {
    User::factory()->withTeam()->create(['email' => 'Legacy-Mixed@Example.com', 'password' => 'password']);

    livewire(Login::class)
        ->fillForm(['email' => 'legacy-mixed@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('signup rejects a password shorter than 8 characters', function (): void {
    $email = 'short-pass-signup-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'short'])
        ->call('authenticate')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

test('the remember me checkbox no longer renders on the login page', function (): void {
    $this->get(url()->getAppUrl('login'))
        ->assertDontSee(__('filament-panels::auth/pages/login.form.remember.label'));
});

test('password login always remembers the session even without a checkbox', function (): void {
    $user = User::factory()->withTeam()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('switching accounts cancels pending MFA without authenticating', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.cancel'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('auth.pending')
        ->assertSessionMissing('login.id');

    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    $this->postJson(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertUnprocessable();
    expect($user->fresh()->recoveryCodes())->toContain('recovery-code-one');
});

test('expired sign-in MFA shows a way to start again', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->travel(11)->minutes();

    $this->get(route('two-factor.login'))
        ->assertSee(__('auth.mfa.expired'))
        ->assertSee(__('auth.mfa.restart'))
        ->assertDontSee('autocomplete="one-time-code"', false);
});

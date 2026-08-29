<?php

declare(strict_types=1);

use App\Features\SocialAuth;
use App\Filament\Pages\Auth\Login;
use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Pennant\Feature;

mutates(Login::class);
mutates(PasskeyLoginResponse::class);

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

test('login screen renders the Sign in with passkey button via the panel render hook', function (): void {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertStatus(200);
    $response->assertSee('Sign in with a passkey');
});

test('login email field has autocomplete=username webauthn for conditional mediation', function (): void {
    livewire(Login::class)
        ->assertSeeHtml('autocomplete="username webauthn"');
});

test('PasskeyLoginResponse contract resolves to our admin-panel response', function (): void {
    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
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
    // Consistent with password and social login: a scheduled-for-deletion user authenticates and
    // the CheckScheduledDeletion middleware routes them to the interstitial where they can cancel.
    // Blocking only the passkey path left passwordless users with a confusing dead-end.
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

test('continue with a password account reveals the password field', function (): void {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('continue with an unknown email behaves like a password account', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'nobody-'.uniqid().'@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
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

test('continue with a social-only account hints the provider', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'social:google');
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
            ->assertSet('authMethod', 'password');
    }

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

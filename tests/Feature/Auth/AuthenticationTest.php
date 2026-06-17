<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

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
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect(url()->getAppUrl((string) $team->slug));

    $this->assertAuthenticated();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
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

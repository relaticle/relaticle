<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\RequireIdentityConfirmation;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

mutates(User::class, RequireIdentityConfirmation::class);

test('two factor authentication can be enabled', function () {
    $this->actingAs($user = User::factory()->create()->fresh());

    $this->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(TwoFactorAuthenticationForm::class)
        ->call('enableTwoFactorAuthentication');

    $user = $user->fresh();

    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->recoveryCodes())->toHaveCount(8);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('recovery codes can be regenerated', function () {
    $this->actingAs($user = User::factory()->withConfirmedMfa()->create()->fresh());

    $this->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(TwoFactorAuthenticationForm::class)
        ->call('regenerateRecoveryCodes');

    $user = $user->fresh();

    $component->call('regenerateRecoveryCodes');

    expect($user->recoveryCodes())->toHaveCount(8);
    expect(array_diff($user->recoveryCodes(), $user->fresh()->recoveryCodes()))->toHaveCount(8);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('two factor authentication can be disabled', function () {
    $this->actingAs($user = User::factory()->withConfirmedMfa()->create()->fresh());

    $this->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(TwoFactorAuthenticationForm::class);

    expect($user->fresh()->two_factor_secret)->not->toBeNull();

    $component->call('disableTwoFactorAuthentication');

    expect($user->fresh()->two_factor_secret)->toBeNull();
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('confirming TOTP enrollment marks the session complete so the next request is not force-logged-out', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(TwoFactorAuthenticationForm::class)
        ->call('enableTwoFactorAuthentication');

    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $component->set('code', $code)->call('confirmTwoFactorAuthentication');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->get(Dashboard::getUrl(['tenant' => $user->currentTeam]))->assertOk();
    $this->assertAuthenticatedAs($user);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('disabling MFA through the direct Fortify route requires its own proven manage_mfa grant, not just a generic confirmation', function () {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $this->deleteJson(route('two-factor.disable'))
        ->assertStatus(423)
        ->assertJson(['message' => __('auth.confirm.required')]);

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    $this->deleteJson(route('two-factor.disable'))
        ->assertUnprocessable()
        ->assertJson(['message' => __('auth.confirm.required')]);

    expect($user->fresh()->two_factor_secret)->not->toBeNull();

    AuthenticationSession::startOperation($user, 'manage_mfa', null);
    AuthenticationSession::proveOperation($user, 'manage_mfa', null);

    $this->deleteJson(route('two-factor.disable'))
        ->assertOk();

    expect($user->fresh()->two_factor_secret)->toBeNull();
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('a generic identity confirmation cannot disclose the two-factor secret', function () {
    $user = User::factory()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $this->getJson(route('two-factor.secret-key'))->assertStatus(423);

    $code = resolve(Google2FA::class)->getCurrentOtp($secret);
    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    $this->getJson(route('two-factor.secret-key'))
        ->assertUnprocessable()
        ->assertJson(['message' => __('auth.confirm.required')]);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('a generic identity confirmation cannot rotate the recovery codes', function () {
    $user = User::factory()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $before = $user->recoveryCodes();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $code = resolve(Google2FA::class)->getCurrentOtp($secret);
    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    $this->postJson(route('two-factor.regenerate-recovery-codes'))
        ->assertUnprocessable()
        ->assertJson(['message' => __('auth.confirm.required')]);

    $this->getJson(route('two-factor.recovery-codes'))
        ->assertUnprocessable();

    expect($user->fresh()->recoveryCodes())->toBe($before);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

test('a proven manage_mfa grant still reaches the two-factor secret', function () {
    $user = User::factory()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $code = resolve(Google2FA::class)->getCurrentOtp($secret);
    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    AuthenticationSession::startOperation($user, 'manage_mfa', null);
    AuthenticationSession::proveOperation($user, 'manage_mfa', null);

    $this->getJson(route('two-factor.secret-key'))
        ->assertOk()
        ->assertJson(['secretKey' => $secret]);
})->skip(function () {
    return ! Features::canManageTwoFactorAuthentication();
}, 'Two factor authentication is not enabled.');

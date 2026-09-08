<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmMfaEnrollment;
use App\Livewire\App\Profile\ManageMfa;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Filament\Actions\Testing\TestAction;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

mutates(ManageMfa::class, ConfirmMfaEnrollment::class);

test('the section renders and reports that MFA is off', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(ManageMfa::class)
        ->assertSuccessful()
        ->assertSee(__('profile.sections.mfa.title'))
        ->assertSee(__('profile.sections.mfa.status_disabled'));
});

test('the section reports that MFA is on for an enrolled user', function (): void {
    $this->actingAs(User::factory()->withTeam()->withConfirmedMfa()->create());

    Livewire::test(ManageMfa::class)
        ->assertSuccessful()
        ->assertSee(__('profile.sections.mfa.status_enabled'));
});

test('enrolling requires an identity proof and does not enforce MFA until a code is confirmed', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $component = Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password']);

    expect($component->get('pendingSecret'))->not->toBeNull()
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('a wrong password cannot start enrolment', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'not-my-password'])
        ->assertHasActionErrors(['password']);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('confirming a valid code turns MFA on and spends the grant', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $component = Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password']);

    $secret = $component->get('pendingSecret');
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $component->callAction(TestAction::make('confirmMfa'), ['code' => $code]);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(AuthenticationSession::pendingOperation())->toBe([]);
});

test('an invalid code leaves MFA off', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password'])
        ->mountAction('confirmMfa')
        ->setActionData(['code' => '000000'])
        ->callMountedAction()
        ->assertHasActionErrors(['code']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('turning MFA off requires the password and the current code', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('disableMfa'), [
            'password' => 'password',
            'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
        ]);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('a wrong password cannot turn MFA off', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('disableMfa'), [
            'password' => 'wrong-password',
            'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
        ])
        ->assertHasActionErrors(['password']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('recovery codes are only revealed after a fresh proof', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $this->actingAs($user);

    $component = Livewire::test(ManageMfa::class);

    expect($component->get('revealedRecoveryCodes'))->toBe([]);

    $component->callAction(TestAction::make('showRecoveryCodes'), [
        'password' => 'password',
        'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
    ]);

    expect($component->get('revealedRecoveryCodes'))->not->toBe([]);
});

test('regenerating recovery codes replaces the previous set', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $before = $user->recoveryCodes();
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('regenerateRecoveryCodes'), [
            'password' => 'password',
            'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
        ]);

    expect($user->fresh()->recoveryCodes())->not->toBe($before);
});

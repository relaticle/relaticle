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
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password'])
        ->assertActionMounted('confirmMfa');

    $component->assertMountedActionModalSee($component->get('pendingSecret'))
        ->assertMountedActionModalSeeHtml('autofocus');

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
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password'])
        ->assertActionMounted('confirmMfa');

    $secret = $component->get('pendingSecret');
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $component->setActionData(['code' => $code])
        ->callMountedAction()
        ->assertActionMounted('saveRecoveryCodes')
        ->assertMountedActionModalSee($user->fresh()->recoveryCodes()[0]);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(AuthenticationSession::pendingOperation())->toBe([]);
});

test('an invalid code leaves MFA off', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password'])
        ->assertActionMounted('confirmMfa')
        ->setActionData(['code' => '000000'])
        ->callMountedAction()
        ->assertHasActionErrors(['code'])
        ->assertActionMounted('confirmMfa');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('setup rejects a code for an authenticator replaced in another tab', function (): void {
    $this->freezeTime();
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $firstTab = Livewire::test(ManageMfa::class)
        ->callAction('enableMfa', ['password' => 'password']);
    $firstSecret = $firstTab->get('pendingSecret');
    $secondTab = Livewire::test(ManageMfa::class)
        ->callAction('enableMfa', ['password' => 'password']);
    $secondSecret = $secondTab->get('pendingSecret');

    $firstTab->setActionData(['code' => resolve(Google2FA::class)->getCurrentOtp($firstSecret)])
        ->callMountedAction()
        ->assertActionMounted('confirmMfa')
        ->assertHasActionErrors(['code']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
    expect(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret))->toBe($secondSecret);

    $secondTab->setActionData(['code' => resolve(Google2FA::class)->getCurrentOtp($secondSecret)])
        ->callMountedAction()
        ->assertActionMounted('saveRecoveryCodes');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('a stale settings tab cannot replace an enrolled authenticator', function (): void {
    $this->freezeTime();
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $staleTab = Livewire::test(ManageMfa::class);
    $activeTab = Livewire::test(ManageMfa::class)
        ->callAction('enableMfa', ['password' => 'password']);
    $secret = $activeTab->get('pendingSecret');
    $activeTab->setActionData(['code' => resolve(Google2FA::class)->getCurrentOtp($secret)])
        ->callMountedAction();
    $this->travel(1)->minutes();
    $this->actingAs($user->fresh());

    $staleTab->callAction('enableMfa', [
        'password' => 'password',
        'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
    ]);

    expect(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret))->toBe($secret);
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
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

test('cancelling setup clears the secret and keeps MFA off', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password'])
        ->call('unmountAction')
        ->assertSet('pendingSecret', null)
        ->assertSet('pendingQrSvg', null)
        ->assertActionHidden('confirmMfa');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('leaving recovery codes hides them while leaving MFA on', function (string $method): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $component = Livewire::test(ManageMfa::class)
        ->callAction(TestAction::make('enableMfa'), ['password' => 'password']);
    $code = resolve(Google2FA::class)->getCurrentOtp($component->get('pendingSecret'));

    $component->setActionData(['code' => $code])
        ->callMountedAction()
        ->assertActionMounted('saveRecoveryCodes')
        ->call($method)
        ->assertSet('revealedRecoveryCodes', [])
        ->assertSet('pendingSecret', null)
        ->assertSet('mountedActions', [])
        ->assertDontSee($user->fresh()->recoveryCodes()[0]);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
})->with(['finish' => 'callMountedAction', 'close' => 'unmountAction']);

test('a user who signs in with a recovery code can disable MFA with another recovery code', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertRedirect();
    $this->assertAuthenticatedAs($user);

    Livewire::test(ManageMfa::class)
        ->callAction('disableMfa', [
            'password' => 'password',
            'use_recovery_code' => true,
            'recovery_code' => 'recovery-code-two',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('profile.sections.mfa.disabled_notification'));

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
});

test('a wrong password does not spend a recovery code while disabling MFA', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    $codes = $user->recoveryCodes();

    Livewire::test(ManageMfa::class)
        ->callAction('disableMfa', [
            'password' => 'incorrect-password',
            'use_recovery_code' => true,
            'recovery_code' => 'recovery-code-one',
        ])
        ->assertHasActionErrors(['password']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($user->fresh()->recoveryCodes())->toBe($codes);
});

test('an invalid recovery code cannot disable MFA', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    $codes = $user->recoveryCodes();

    Livewire::test(ManageMfa::class)
        ->callAction('disableMfa', [
            'password' => 'password',
            'use_recovery_code' => true,
            'recovery_code' => 'invalid-recovery-code',
        ])
        ->assertHasActionErrors(['recovery_code']);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($user->fresh()->recoveryCodes())->toBe($codes);
});

<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmIdentity;
use App\Actions\Fortify\UpdateUserPassword;
use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\IdentityConfirmationController;
use App\Livewire\App\Profile\UpdatePassword as UpdatePasswordComponent;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

mutates(UpdatePasswordComponent::class, UpdateUserPassword::class, IdentityConfirmationController::class, ConfirmIdentity::class);

test('password component renders correctly', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password');
});

test('password can be updated', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->setActionData(['password' => 'password'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('current password must be correct', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->setActionData(['password' => 'wrong-password'])
        ->callMountedAction()
        ->assertHasActionErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('new passwords must match', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'wrong-password',
        ])
        ->call('updatePassword')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('social user sees set password form without current password field', function () {
    $this->actingAs(User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Set Password')
        ->assertDontSee('Current Password');
});

test('a passwordless session cannot set a password without identity confirmation', function (): void {
    $this->actingAs($user = User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertActionMounted('save');

    expect($user->fresh()->password)->toBeNull();
});

test('the password endpoint rejects a correct password without a scoped grant', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);
    AuthenticationSession::markComplete($user);

    $this->putJson(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('identity');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('social user who set a password then sees update password form', function () {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $user->forceFill(['password' => Hash::make('my-password')])->save();

    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password')
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertMountedActionModalSee(__('profile.form.password.label'));
});

test('an enrolled account must provide MFA before saving a password', function (): void {
    $this->freezeTime();
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $component = Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->setActionData(['password' => 'password'])
        ->callMountedAction()
        ->assertHasActionErrors(['code']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    $component->setActionData([
        'password' => 'password',
        'code' => resolve(Google2FA::class)->getCurrentOtp($secret),
    ])->callMountedAction()->assertHasNoActionErrors()->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

test('a passwordless account can set a password after returning from its linked provider', function (): void {
    $user = User::factory()->withTeam()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'password-settings-google',
    ]);
    $this->actingAs($user);
    $socialUser = new SocialiteUser;
    $socialUser->id = 'password-settings-google';
    $socialUser->name = $user->name;
    $socialUser->email = $user->email;
    Socialite::fake(SocialiteProvider::GOOGLE->value, $socialUser);
    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertActionMounted('save');

    $this->get(route('auth.socialite.confirm.redirect', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirect();
    $this->get(route('auth.socialite.confirm.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

test('the password endpoint consumes the confirmed password grant once', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $exceptionHandler = Exceptions::handler();
    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->call('updatePassword');
    Exceptions::setHandler($exceptionHandler);
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertNoContent();

    $this->putJson(route('user-password.update'), [
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    $this->putJson(route('user-password.update'), [
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('identity');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('a direct POST to the confirm-password endpoint does not confirm an MFA-enrolled user without a code', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a direct POST to the confirm-password endpoint rejects an incorrect MFA code', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a direct POST to the confirm-password endpoint confirms an MFA-enrolled user with the correct code', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson(route('password.confirm.store'), ['password' => 'password', 'code' => $code])
        ->assertNoContent();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a direct POST to the confirm-password endpoint confirms a user without enrolled MFA', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertNoContent();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('a direct POST to the confirm-password endpoint rejects an incorrect password', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a direct POST to the confirm-password endpoint throttles repeated incorrect passwords', function (): void {
    $this->actingAs(User::factory()->create());

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('password.confirm.store'), ['password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

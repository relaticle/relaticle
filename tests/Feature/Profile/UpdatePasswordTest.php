<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmIdentity;
use App\Http\Controllers\Auth\IdentityConfirmationController;
use App\Livewire\App\Profile\UpdatePassword as UpdatePasswordComponent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

mutates(UpdatePasswordComponent::class, IdentityConfirmationController::class, ConfirmIdentity::class);

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
            'currentPassword' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('current password must be correct', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'currentPassword' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('new passwords must match', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'currentPassword' => 'password',
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

test('social user can set a password', function () {
    $this->actingAs($user = User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('social user who set a password then sees update password form', function () {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $user->forceFill(['password' => Hash::make('my-password')])->save();

    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password')
        ->assertSee('Current Password');
});

// --- Direct password-confirmation endpoint ---------------------------------

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

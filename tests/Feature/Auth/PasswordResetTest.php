<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

mutates(RequestPasswordReset::class, ResetPassword::class);

test('forgot password page can be rendered', function () {
    $response = $this->get(url()->getAppUrl('password-reset/request'));

    $response->assertSuccessful();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create();

    livewire(RequestPasswordReset::class)
        ->fillForm([
            'email' => $user->email,
        ])
        ->call('request')
        ->assertNotified();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('reset password link is not sent for non-existent email', function () {
    Notification::fake();

    livewire(RequestPasswordReset::class)
        ->fillForm([
            'email' => 'nonexistent@example.com',
        ])
        ->call('request');

    Notification::assertNothingSent();
});

test('password can be reset with valid token', function () {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    livewire(ResetPassword::class, [
        'email' => $user->email,
        'token' => $token,
    ])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'new-secure-password',
        ])
        ->call('resetPassword')
        ->assertNotified()
        ->assertRedirect();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->withTeam()->create();

    livewire(ResetPassword::class, [
        'email' => $user->email,
        'token' => 'invalid-token',
    ])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'new-secure-password',
        ])
        ->call('resetPassword')
        ->assertNotified();

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('password reset requires confirmation to match', function () {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    livewire(ResetPassword::class, [
        'email' => $user->email,
        'token' => $token,
    ])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'different-password',
        ])
        ->call('resetPassword')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('authenticated user is redirected from forgot password page', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    livewire(RequestPasswordReset::class)
        ->assertRedirect();
});

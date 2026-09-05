<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
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

test('an unknown email answers with the same sent notification as a known one', function () {
    Notification::fake();

    livewire(RequestPasswordReset::class)
        ->fillForm([
            'email' => 'nonexistent-'.uniqid().'@example.com',
        ])
        ->call('request')
        ->assertNotified(__(Password::RESET_LINK_SENT))
        ->assertSet('data.email', null);

    Notification::assertNothingSent();
});

test('a mixed-case typed email still reaches the account that owns the mailbox', function () {
    Notification::fake();

    $user = User::factory()->withTeam()->create(['email' => 'reset-case-'.uniqid().'@example.com']);

    livewire(RequestPasswordReset::class)
        ->fillForm([
            'email' => mb_strtoupper($user->email),
        ])
        ->call('request')
        ->assertNotified(__(Password::RESET_LINK_SENT));

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('the fortify forgot-password endpoint is not exposed', function () {
    $this->post('/forgot-password', ['email' => 'nonexistent@example.com'])
        ->assertStatus(405);
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

test('signed password reset URL is accessible', function () {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    $url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

    $this->get($url)->assertSuccessful();
});

test('password reset URL with decoded percent-encoding is accessible', function () {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    $url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

    $this->get(urldecode($url))->assertSuccessful();
});

test('password reset URL with tampered email is rejected', function () {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    $url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

    // Tamper with the email parameter
    $tamperedUrl = preg_replace('/email=[^&]+/', 'email=hacker@example.com', $url);

    $this->get($tamperedUrl)->assertForbidden();
});

test('authenticated user is redirected from forgot password page', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    livewire(RequestPasswordReset::class)
        ->assertRedirect();
});

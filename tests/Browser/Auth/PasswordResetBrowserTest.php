<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

mutates(RequestPasswordReset::class, ResetPassword::class);

it('can navigate to forgot password from login and request a reset link', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->visit('/app/login')
        ->click('a[href*="password-reset"]')
        ->assertPathContains('/password-reset/request')
        ->assertSee('Forgot password?')
        ->type('[id="form.email"]', $user->email)
        ->press('Send email')
        ->waitForText('We have emailed your password reset link', 10)
        ->assertSee('We have emailed your password reset link');
});

it('can reset password using a valid reset link', function (): void {
    $user = User::factory()->withTeam()->create();
    $token = Password::broker('users')->createToken($user);

    $resetUrl = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

    $this->visit('/app/login')
        ->navigate($resetUrl)
        ->assertSee('Reset password')
        ->type('[id="form.password"]', 'new-secure-password')
        ->type('[id="form.passwordConfirmation"]', 'new-secure-password')
        ->press('Reset password')
        ->waitForText('Your password has been reset', 10)
        ->assertPathContains('/login');

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

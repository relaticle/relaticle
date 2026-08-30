<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

mutates(FortifyServiceProvider::class);
mutates(EmailVerificationPrompt::class);

test('unverified user hitting the Fortify verification-notice route is sent to the Filament prompt', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertRedirect(Filament::getPanel('app')->getEmailVerificationPromptUrl());
});

test('verified user hitting the Fortify verification-notice route is redirected away from the prompt', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/email/verify');

    $response->assertRedirect();

    expect($response->headers->get('Location'))
        ->not->toBe(Filament::getPanel('app')->getEmailVerificationPromptUrl());
});

test('the prompt names the address the verification link was sent to', function () {
    $user = User::factory()->unverified()->create(['email' => 'pending@example.test']);

    $this->actingAs($user);

    livewire(EmailVerificationPrompt::class)
        ->assertSee(__('auth.verify_email.heading'))
        ->assertSee('pending@example.test')
        ->assertSee(__('auth.verify_email.resend'));
});

test('the prompt resends the verification email', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    livewire(EmailVerificationPrompt::class)
        ->callAction('resendNotification')
        ->assertNotified();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('resending starts a cooldown that outlives the page it was started on', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    livewire(EmailVerificationPrompt::class)
        ->callAction('resendNotification')
        ->assertSet('resendCooldownSeconds', fn (int $seconds): bool => $seconds > 0);

    expect(livewire(EmailVerificationPrompt::class)->instance()->getResendCooldownSeconds())
        ->toBeGreaterThan(0);
});

test('the prompt lets a signed-in user sign out to use another address', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    $logoutUrl = Filament::getPanel('app')->getLogoutUrl();

    livewire(EmailVerificationPrompt::class)
        ->assertSee(__('auth.verify_email.sign_out'))
        ->assertSee($logoutUrl, escape: false);

    $this->post($logoutUrl)->assertRedirect(Filament::getPanel('app')->getLoginUrl());

    $this->assertGuest();
});

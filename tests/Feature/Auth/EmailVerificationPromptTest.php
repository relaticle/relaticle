<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Notifications\Auth\VerifyEmail;
use App\Providers\FortifyServiceProvider;
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

test('the emailed verification link persists verification for the signed-in recipient', function (): void {
    $user = User::factory()->unverified()->create();
    $notification = new VerifyEmail;
    $notification->url = Filament::getVerifyEmailUrl($user);
    $html = (string) $notification->toMail($user)->render();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $link = (new DOMXPath($document))->query('//a[contains(@href, "email-verification/verify")]')->item(0);

    expect($link)->toBeInstanceOf(DOMElement::class);

    $this->actingAs($user)->get($link->getAttribute('href'))->assertRedirect();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('opening a verification link without a session requires login and resumes verification afterward', function (): void {
    $user = User::factory()->unverified()->create();
    $url = Filament::getVerifyEmailUrl($user);

    $this->get($url)
        ->assertRedirect(Filament::getLoginUrl())
        ->assertSessionHas('url.intended', $url);

    expect($user->refresh()->email_verified_at)->toBeNull();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect($url);

    $this->get($url)->assertRedirect();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('an expired verification link leaves the recipient unverified', function (): void {
    $this->freezeTime();
    $user = User::factory()->unverified()->create();
    $url = Filament::getVerifyEmailUrl($user);
    $this->travel(61)->minutes();

    $this->actingAs($user)->get($url)->assertForbidden();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('a verification link cannot verify a different signed-in user', function (): void {
    $recipient = User::factory()->unverified()->create();
    $otherUser = User::factory()->unverified()->create();

    $this->actingAs($otherUser)->get(Filament::getVerifyEmailUrl($recipient))->assertForbidden();

    expect($recipient->refresh()->email_verified_at)->toBeNull()
        ->and($otherUser->refresh()->email_verified_at)->toBeNull();
});

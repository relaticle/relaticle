<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\NoticeOfEmailChangeRequest;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use App\Notifications\Auth\VerifyEmailChange;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest as FilamentNoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Filament\Auth\Notifications\VerifyEmailChange as FilamentVerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

mutates(VerifyEmail::class);
mutates(VerifyEmailChange::class);
mutates(ResetPassword::class);
mutates(NoticeOfEmailChangeRequest::class);

it('resolves the branded subclasses from the filament class names', function (): void {
    expect(resolve(FilamentVerifyEmail::class))->toBeInstanceOf(VerifyEmail::class)
        ->and(resolve(FilamentVerifyEmailChange::class))->toBeInstanceOf(VerifyEmailChange::class)
        ->and(resolve(FilamentResetPassword::class, ['token' => 'abc']))->toBeInstanceOf(ResetPassword::class)
        ->and(resolve(FilamentNoticeOfEmailChangeRequest::class, ['newEmail' => 'new@example.com', 'blockVerificationUrl' => 'https://relaticle.test/block']))
        ->toBeInstanceOf(NoticeOfEmailChangeRequest::class);
});

it('renders the verify email mail with the branded copy', function (): void {
    $user = User::factory()->unverified()->create();
    $notification = new VerifyEmail;
    $notification->url = 'https://app.relaticle.test/verify/123';

    $message = $notification->toMail($user);

    expect($message->subject)->toBe(__('mail.verify_email.subject'))
        ->and((string) $message->render())->toContain(__('mail.verify_email.heading'))
        ->and((string) $message->render())->toContain('https://app.relaticle.test/verify/123')
        ->and((string) $message->render())->toContain(__('mail.footer.reason.account', ['company' => config('relaticle.company.name')]));
});

it('renders the verify email change mail naming the new address and the link expiry', function (): void {
    config(['auth.verification.expire' => 45]);
    $notification = new VerifyEmailChange;
    $notification->url = 'https://app.relaticle.test/verify-change/123';

    $message = $notification->toMail(Notification::route('mail', 'new@example.com'));
    $html = (string) $message->render();

    expect($message->subject)->toBe(__('mail.verify_email_change.subject'))
        ->and($html)->toContain(__('mail.verify_email_change.heading', ['email' => 'new@example.com']))
        ->and($html)->toContain('This link expires in 45 minutes.')
        ->and($html)->not->toContain('mail.verify_email_change.');
});

it('renders the email change notice naming the new address', function (): void {
    $user = User::factory()->create();

    $message = (new NoticeOfEmailChangeRequest('new@example.com', 'https://relaticle.test/block'))->toMail($user);

    expect($message->subject)->toBe(__('mail.email_change_notice.subject'))
        ->and((string) $message->render())->toContain(__('mail.email_change_notice.heading', ['email' => 'new@example.com']))
        ->and((string) $message->render())->toContain('href="https://relaticle.test/block"');
});

it('renders the reset password mail with the expiry', function (): void {
    config(['auth.passwords.users.expire' => 45]);
    $user = User::factory()->create();
    $notification = new ResetPassword('token');
    $notification->url = 'https://app.relaticle.test/reset/token';

    $message = $notification->toMail($user);

    expect($message->subject)->toBe(__('mail.reset_password.subject'))
        ->and((string) $message->render())->toContain(__('mail.reset_password.body', ['count' => 45]));
});

it('sends the branded verify mail through the model method with the panel verification url', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo(
        $user,
        VerifyEmail::class,
        fn (VerifyEmail $notification): bool => $notification->url === Filament::getVerifyEmailUrl($user),
    );
});

it('sends the branded reset mail through the model method with the panel reset url', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $user->sendPasswordResetNotification('token');

    Notification::assertSentTo(
        $user,
        ResetPassword::class,
        fn (ResetPassword $notification): bool => $notification->url === Filament::getResetPasswordUrl('token', $user),
    );
});

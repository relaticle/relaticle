<?php

declare(strict_types=1);

use App\Enums\EmailChallengePurpose;
use App\Jobs\Email\SendEmailChallenge;
use App\Models\EmailChallenge;
use App\Models\User;
use App\Notifications\Auth\EmailCode;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

mutates(SendEmailChallenge::class, EmailCode::class);

test('the queued payload never carries the plaintext code at rest', function (): void {
    config(['queue.default' => 'database']);
    $user = User::factory()->create();
    $challenge = EmailChallenge::factory()->forUser($user)->create();

    SendEmailChallenge::dispatch($challenge->id, '482913');

    $payload = DB::table('jobs')->value('payload');

    expect($payload)->not->toBeNull()
        ->and($payload)->not->toContain('482913')
        ->and($payload)->not->toContain($challenge->id);
});

test('the job sends nothing for a superseded generation', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $challenge = EmailChallenge::factory()->forUser($user)->invalidated('superseded')->create();

    SendEmailChallenge::dispatch($challenge->id, '482913');

    Notification::assertNothingSent();
});

test('the job sends nothing for an already consumed challenge', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $challenge = EmailChallenge::factory()->forUser($user)->consumed()->create();

    SendEmailChallenge::dispatch($challenge->id, '482913');

    Notification::assertNothingSent();
});

test('the job sends nothing for an expired challenge', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $challenge = EmailChallenge::factory()->forUser($user)->expired()->create();

    SendEmailChallenge::dispatch($challenge->id, '482913');

    Notification::assertNothingSent();
});

test('the job sends nothing for a challenge that exhausted its failed attempts', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $challenge = EmailChallenge::factory()->forUser($user)->create([
        'failed_attempts' => EmailChallenge::MAX_FAILED_ATTEMPTS,
    ]);

    SendEmailChallenge::dispatch($challenge->id, '482913');

    Notification::assertNothingSent();
});

test('the job sends nothing when the challenge no longer exists', function (): void {
    Notification::fake();

    SendEmailChallenge::dispatch((string) Str::ulid(), '482913');

    Notification::assertNothingSent();
});

test('the job sends the code to the challenge email for a live generation', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'pending@example.test']);
    $challenge = EmailChallenge::factory()->forUser($user)->create();

    SendEmailChallenge::dispatch($challenge->id, '482913');

    Notification::assertSentOnDemand(
        EmailCode::class,
        fn (EmailCode $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'pending@example.test',
    );
});

test('retries are bounded by the code\'s own remaining lifetime, not a fixed window', function (): void {
    $challenge = EmailChallenge::factory()->create();

    $job = new SendEmailChallenge($challenge->id, '482913');

    expect($job->retryUntil()?->equalTo($challenge->expires_at))->toBeTrue();
});

test('a missing challenge has no bounded retry window', function (): void {
    $job = new SendEmailChallenge((string) Str::ulid(), '482913');

    expect($job->retryUntil())->toBeNull();
});

test('the mail names the purpose, the code, and the expiry window', function (): void {
    $notification = new EmailCode('482913', EmailChallengePurpose::VERIFY_EMAIL, 15);

    $message = $notification->toMail(Notification::route('mail', 'pending@example.test'));
    $html = (string) $message->render();

    expect($message->subject)->toBe(__('mail.email_code.purposes.verify_email.subject'))
        ->and($html)->toContain(__('mail.email_code.purposes.verify_email.heading'))
        ->and($html)->toContain('482913')
        ->and($html)->toContain('This code expires in 15 minutes.');
});

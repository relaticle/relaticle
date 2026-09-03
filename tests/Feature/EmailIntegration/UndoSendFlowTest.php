<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;

mutates(CancelQueuedEmailAction::class, SyncEmailBatchCountersAction::class);

it('cancels a single send within the 30s undo window', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->for($user)->create([
        'team_id' => $user->currentTeam->getKey(),
    ]));

    $this->travelTo(now()->startOfSecond());
    $email = resolve(SendEmailAction::class)->execute([
        'connected_account_id' => $account->getKey(),
        'subject' => 'Hi',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'a@b.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'priority' => EmailPriority::PRIORITY,
        'scheduled_for' => null,
        'batch_id' => null,
        'in_reply_to_email_id' => null,
    ]);

    expect((int) round(abs($email->scheduled_for?->diffInSeconds(now()) ?? 0.0)))->toBe(30);

    resolve(CancelQueuedEmailAction::class)->execute($email->refresh());

    expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('rejects undo once the email is claimed for sending', function (): void {
    // SENDING means a worker is actively delivering. send() calls the provider
    // outside any row lock, so cancelling here could mark CANCELLED an email the
    // provider already accepted. Undo is only safe while the email is still QUEUED.
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => EmailStatus::SENDING,
        'provider_message_id' => null,
    ]));

    expect(fn () => resolve(CancelQueuedEmailAction::class)->execute($email))
        ->toThrow(RuntimeException::class);

    expect($email->refresh()->status)->toBe(EmailStatus::SENDING);
});

it('rejects undo once Gmail has accepted the message', function (): void {
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => EmailStatus::SENDING,
        'provider_message_id' => 'gmail-msg-id-123',
    ]));

    expect(fn () => resolve(CancelQueuedEmailAction::class)->execute($email))
        ->toThrow(RuntimeException::class);
});

it('rejects undo on terminal statuses', function (EmailStatus $status): void {
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => $status,
        'provider_message_id' => $status === EmailStatus::SENT ? 'gmail-msg-id-xyz' : null,
    ]));

    expect(fn () => resolve(CancelQueuedEmailAction::class)->execute($email))
        ->toThrow(RuntimeException::class);
})->with([
    EmailStatus::SENT,
    EmailStatus::CANCELLED,
    EmailStatus::FAILED,
]);

it('finishes the batch when a queued recipient is cancelled', function (
    EmailStatus $otherStatus,
    EmailBatchStatus $expectedStatus,
    int $sentCount,
    int $failedCount,
): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->for($user)->create([
        'team_id' => $user->currentTeam->getKey(),
    ]));

    $batch = EmailBatch::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'total_recipients' => 2,
        'status' => EmailBatchStatus::Sending,
    ]);

    Email::factory()->outbound()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'batch_id' => $batch->getKey(),
        'status' => $otherStatus,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    $queued = Email::factory()->outbound()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::QUEUED,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    resolve(CancelQueuedEmailAction::class)->execute($queued);

    expect($queued->refresh()->status)->toBe(EmailStatus::CANCELLED);

    expect($batch->fresh())
        ->status->toBe($expectedStatus)
        ->sent_count->toBe($sentCount)
        ->failed_count->toBe($failedCount);
})->with([
    'other recipient already sent' => [EmailStatus::SENT, EmailBatchStatus::Completed, 1, 0],
    'other recipient already failed' => [EmailStatus::FAILED, EmailBatchStatus::PartialFailure, 0, 1],
    'other recipient already cancelled' => [EmailStatus::CANCELLED, EmailBatchStatus::Completed, 0, 0],
]);

it('does not finish the batch while another recipient is still queued', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->for($user)->create([
        'team_id' => $user->currentTeam->getKey(),
    ]));

    $batch = EmailBatch::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'total_recipients' => 2,
        'status' => EmailBatchStatus::Sending,
    ]);

    $stillQueued = Email::factory()->outbound()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::QUEUED,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    $cancelled = Email::factory()->outbound()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::QUEUED,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    resolve(CancelQueuedEmailAction::class)->execute($cancelled);

    expect($stillQueued->refresh()->status)->toBe(EmailStatus::QUEUED);

    expect($batch->fresh())
        ->status->toBe(EmailBatchStatus::Sending)
        ->sent_count->toBe(0)
        ->failed_count->toBe(0);
});

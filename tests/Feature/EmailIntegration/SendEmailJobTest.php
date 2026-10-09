<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\MarkEmailsSendFailedAction;
use Relaticle\EmailIntegration\Actions\RetryFailedEmailAction;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Jobs\SendEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailSendingService;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;

mutates(SendEmailJob::class, SyncEmailBatchCountersAction::class, MarkEmailsSendFailedAction::class, RetryFailedEmailAction::class, EmailSendFailedNotification::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
});

it('records exception class and message on the email when the job fails', function (): void {
    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Outbound',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $exception = new RuntimeException('boom');

    (new SendEmailJob($email->getKey()))->failed($exception);

    expect($email->fresh())
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe('RuntimeException: boom');
});

it('tells the sender when a send fails', function (): void {
    Notification::fake();

    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Proposal',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    (new SendEmailJob($email->getKey()))->failed(new RuntimeException('boom'));

    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->count === 1
        && $notification->subject === 'Proposal');
});

it('tells the sender once when several emails in one mass send fail', function (): void {
    Notification::fake();

    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 3,
    ]);

    foreach (range(1, 3) as $index) {
        $email = Email::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->id,
            'batch_id' => $batch->getKey(),
            'subject' => "Mass send {$index}",
            'direction' => EmailDirection::OUTBOUND,
            'status' => EmailStatus::SENDING,
            'privacy_tier' => EmailPrivacyTier::FULL,
            'creation_source' => EmailCreationSource::COMPOSE,
        ]);

        (new SendEmailJob($email->getKey()))->failed(new RuntimeException('boom'));
    }

    resolve(SyncEmailBatchCountersAction::class)->execute($batch->getKey());

    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->count === 3);
});

it('tells the sender again when a retried mass-send email fails again', function (): void {
    Notification::fake();

    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 1,
    ]);

    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'subject' => 'Mass send',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    (new SendEmailJob($email->getKey()))->failed(new RuntimeException('boom'));
    resolve(RetryFailedEmailAction::class)->execute($email);

    expect($batch->fresh()->status)->toBe(EmailBatchStatus::Sending);

    (new SendEmailJob($email->getKey()))->failed(new RuntimeException('boom again'));

    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 2);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->count === 1
        && $notification->subject === 'Mass send');
});

it('does not report a mass send again when a retried email then sends', function (): void {
    Notification::fake();

    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 2,
    ]);

    $emails = collect(range(1, 2))->map(fn (int $index): Email => Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'subject' => "Mass send {$index}",
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]));

    $emails->each(fn (Email $email) => (new SendEmailJob($email->getKey()))->failed(new RuntimeException('boom')));
    resolve(RetryFailedEmailAction::class)->execute($emails->first());
    $emails->first()->update(['status' => EmailStatus::SENT]);
    resolve(SyncEmailBatchCountersAction::class)->execute($batch->getKey());

    expect($batch->fresh()->status)->toBe(EmailBatchStatus::PartialFailure);
    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
});

it('fails instead of sending when the mailbox was disconnected after the email was claimed', function (): void {
    Notification::fake();

    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Proposal',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $this->account->delete();

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');
    app()->instance(MailServiceFactoryInterface::class, $factory);

    (new SendEmailJob($email->getKey()))->handle(resolve(EmailSendingService::class), resolve(LinkEmailAction::class));

    expect(Email::withoutGlobalScopes()->findOrFail($email->getKey()))
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe(__('filament/notifications/email-send-failed.reasons.mailbox_needs_reconnect'));

    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->mailboxNeedsReconnect);
});

it('drops an overlapping duplicate send job instead of releasing it to fail later', function (): void {
    $job = (new SendEmailJob('email-1'))->withFakeQueueInteractions();
    $overlap = $job->middleware()[0];
    Cache::lock($overlap->getLockKey($job), 60)->get();
    $ran = false;

    $overlap->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    $job->assertNotReleased();
});

it('does not mark a delivered email as failed when a later job step throws', function (): void {
    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Outbound',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENT,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
        'last_error' => null,
    ]);

    (new SendEmailJob($email->getKey()))->failed(new RuntimeException('link failed'));

    expect($email->fresh())
        ->status->toBe(EmailStatus::SENT)
        ->last_error->toBeNull();
});

it('logs the full exception when the job fails', function (): void {
    $email = Email::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Outbound',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $exception = new RuntimeException('kaboom');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($email, $exception): bool {
            return $message === 'SendEmailJob failed'
                && $context['email_id'] === $email->getKey()
                && $context['exception'] === $exception;
        });

    (new SendEmailJob($email->getKey()))->failed($exception);
});

it('completes the batch when a later step fails after the provider already accepted the message', function (): void {
    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 1,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    $email = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::SENDING,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
        'rfc_message_id' => '<send-job-batch@example.com>',
        'provider_message_id' => null,
        'thread_id' => null,
        'attempts' => 0,
    ]);

    $email->body()->create(['body_text' => 'hi', 'body_html' => '<p>hi</p>']);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'recipient@partner.com',
    ]);

    $mail = new class implements MailServiceInterface
    {
        public function fetchDelta(string $cursor): MailDeltaResult
        {
            throw new LogicException('unused');
        }

        public function fetchMessage(string $providerMessageId): FetchedEmailData
        {
            throw new LogicException('unused');
        }

        public function initialBackfill(?int $daysBack = null, ?string $pageToken = null): MailBackfillPage
        {
            throw new LogicException('unused');
        }

        public function sendMessage(array $data): array
        {
            return [
                'provider_message_id' => 'sent-123',
                'thread_id' => 'thread-123',
                'rfc_message_id' => $data['rfc_message_id'] ?? '<derived@example.com>',
            ];
        }

        public function findSentMessage(string $rfcMessageId): ?array
        {
            return null;
        }

        public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
        {
            return '';
        }
    };

    app()->bind(MailServiceFactoryInterface::class, fn (): MailServiceFactoryInterface => new class($mail) implements MailServiceFactoryInterface
    {
        public function __construct(private readonly MailServiceInterface $service) {}

        public function make(ConnectedAccount $account): MailServiceInterface
        {
            return $this->service;
        }
    });

    $crashAfterDelivery = true;
    Email::updated(function (Email $updated) use (&$crashAfterDelivery): void {
        if ($crashAfterDelivery && $updated->status === EmailStatus::SENT) {
            $crashAfterDelivery = false;
            throw new RuntimeException('link failed');
        }
    });

    resolve(CurrentWorkspace::class)->set(Workspace::query()->findOrFail($email->workspace_id));
    $job = new SendEmailJob($email->getKey());
    $sendingService = app(EmailSendingService::class);
    $linkEmailAction = app(LinkEmailAction::class);

    expect(fn () => $job->handle($sendingService, $linkEmailAction))
        ->toThrow(RuntimeException::class);

    expect($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($batch->fresh())
        ->sent_count->toBe(0)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Sending);

    $job->handle($sendingService, $linkEmailAction);

    expect($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($batch->fresh())
        ->sent_count->toBe(1)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Completed);

    $job->handle($sendingService, $linkEmailAction);

    $job->failed(new RuntimeException('link failed'));

    expect($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($batch->fresh())
        ->sent_count->toBe(1)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Completed);
});

it('retries linking after a post-send crash without double-counting the batch or CRM metrics', function (): void {
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->first();

    if (! $emailsField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Retry Link Person',
        'creator_id' => $this->user->id,
        'email_count' => 0,
        'outbound_email_count' => 0,
    ]);
    $person->saveCustomFieldValue($emailsField, ['recipient@partner.com'], $this->workspace);

    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 1,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    $email = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::SENDING,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
        'rfc_message_id' => '<send-job-link@example.com>',
        'provider_message_id' => null,
        'thread_id' => null,
        'attempts' => 0,
    ]);

    $email->body()->create(['body_text' => 'hi', 'body_html' => '<p>hi</p>']);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'recipient@partner.com',
    ]);

    $mail = new class implements MailServiceInterface
    {
        public int $sendCount = 0;

        public function fetchDelta(string $cursor): MailDeltaResult
        {
            throw new LogicException('unused');
        }

        public function fetchMessage(string $providerMessageId): FetchedEmailData
        {
            throw new LogicException('unused');
        }

        public function initialBackfill(?int $daysBack = null, ?string $pageToken = null): MailBackfillPage
        {
            throw new LogicException('unused');
        }

        public function sendMessage(array $data): array
        {
            $this->sendCount++;

            return [
                'provider_message_id' => 'sent-link-123',
                'thread_id' => 'thread-link-123',
                'rfc_message_id' => $data['rfc_message_id'] ?? '<derived-link@example.com>',
            ];
        }

        public function findSentMessage(string $rfcMessageId): ?array
        {
            return null;
        }

        public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
        {
            return '';
        }
    };

    app()->bind(MailServiceFactoryInterface::class, fn (): MailServiceFactoryInterface => new class($mail) implements MailServiceFactoryInterface
    {
        public function __construct(private readonly MailServiceInterface $service) {}

        public function make(ConnectedAccount $account): MailServiceInterface
        {
            return $this->service;
        }
    });

    $throwOnLink = false;
    Email::updated(function (Email $updated) use (&$throwOnLink): void {
        if ($updated->status === EmailStatus::SENT) {
            $throwOnLink = true;
        }
    });
    EmailParticipant::retrieved(function () use (&$throwOnLink): void {
        if (! $throwOnLink) {
            return;
        }

        $throwOnLink = false;

        throw new RuntimeException('link failed');
    });

    resolve(CurrentWorkspace::class)->set(Workspace::query()->findOrFail($email->workspace_id));
    $job = new SendEmailJob($email->getKey());
    $sendingService = app(EmailSendingService::class);
    $linkEmailAction = app(LinkEmailAction::class);

    expect(fn () => $job->handle($sendingService, $linkEmailAction))
        ->toThrow(RuntimeException::class);

    expect($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($email->people()->whereKey($person->getKey())->exists())->toBeFalse()
        ->and($mail->sendCount)->toBe(1);

    $job->handle($sendingService, $linkEmailAction);

    expect($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($email->people()->whereKey($person->getKey())->exists())->toBeTrue()
        ->and($person->fresh()->email_count)->toBe(1)
        ->and($person->fresh()->outbound_email_count)->toBe(1)
        ->and($mail->sendCount)->toBe(1)
        ->and($batch->fresh())
        ->sent_count->toBe(1)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Completed);

    $job->handle($sendingService, $linkEmailAction);
    $job->failed(new RuntimeException('link failed'));

    expect($person->fresh()->email_count)->toBe(1)
        ->and($batch->fresh())
        ->sent_count->toBe(1)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Completed)
        ->and($mail->sendCount)->toBe(1);
});

it('completes the batch when a job finds the email already cancelled', function (): void {
    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 2,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::SENT,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    $cancelled = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::CANCELLED,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    $job = new SendEmailJob($cancelled->getKey());
    $job->handle(app(EmailSendingService::class), app(LinkEmailAction::class));

    expect($cancelled->fresh()->status)->toBe(EmailStatus::CANCELLED);

    expect($batch->fresh())
        ->sent_count->toBe(1)
        ->failed_count->toBe(0)
        ->status->toBe(EmailBatchStatus::Completed);
});

it('does not deliver the same email twice when a second attempt overlaps the first', function (): void {
    $email = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'status' => EmailStatus::SENDING,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
        'rfc_message_id' => '<send-job-overlap@example.com>',
        'provider_message_id' => null,
        'thread_id' => null,
        'attempts' => 0,
    ]);

    $email->body()->create(['body_text' => 'hi', 'body_html' => '<p>hi</p>']);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'recipient@partner.com',
    ]);

    $mail = new class implements MailServiceInterface
    {
        public int $sendCount = 0;

        public ?string $emailId = null;

        public ?EmailSendingService $sendingService = null;

        public ?LinkEmailAction $linkEmailAction = null;

        public function fetchDelta(string $cursor): MailDeltaResult
        {
            throw new LogicException('unused');
        }

        public function fetchMessage(string $providerMessageId): FetchedEmailData
        {
            throw new LogicException('unused');
        }

        public function initialBackfill(?int $daysBack = null, ?string $pageToken = null): MailBackfillPage
        {
            throw new LogicException('unused');
        }

        public function sendMessage(array $data): array
        {
            $this->sendCount++;

            $emailId = $this->emailId;
            $sendingService = $this->sendingService;
            $linkEmailAction = $this->linkEmailAction;

            if ($emailId === null || $sendingService === null || $linkEmailAction === null) {
                throw new LogicException('nested send is not arranged');
            }

            (new SendEmailJob($emailId))->handle($sendingService, $linkEmailAction);

            return [
                'provider_message_id' => 'sent-overlap-123',
                'thread_id' => 'thread-overlap-123',
                'rfc_message_id' => $data['rfc_message_id'] ?? '<derived-overlap@example.com>',
            ];
        }

        public function findSentMessage(string $rfcMessageId): ?array
        {
            return null;
        }

        public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
        {
            return '';
        }
    };

    app()->bind(MailServiceFactoryInterface::class, fn (): MailServiceFactoryInterface => new class($mail) implements MailServiceFactoryInterface
    {
        public function __construct(private readonly MailServiceInterface $service) {}

        public function make(ConnectedAccount $account): MailServiceInterface
        {
            return $this->service;
        }
    });

    $mail->emailId = $email->getKey();
    $mail->sendingService = app(EmailSendingService::class);
    $mail->linkEmailAction = app(LinkEmailAction::class);

    $job = new SendEmailJob($email->getKey());
    $job->handle($mail->sendingService, $mail->linkEmailAction);

    expect($mail->sendCount)->toBe(1)
        ->and($email->fresh()->status)->toBe(EmailStatus::SENT)
        ->and($email->fresh()->attempts)->toBe(1);

    $job->handle($mail->sendingService, $mail->linkEmailAction);

    expect($mail->sendCount)->toBe(1)
        ->and($email->fresh()->attempts)->toBe(1);
});

it('releases the send for a later attempt when the provider rate limits the mailbox', function (): void {
    Notification::fake();

    $email = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'status' => EmailStatus::SENDING,
        'sent_at' => null,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
        'rfc_message_id' => '<send-job-rate-limited@example.com>',
        'provider_message_id' => null,
        'thread_id' => null,
        'attempts' => 0,
    ]);

    $email->body()->create(['body_text' => 'hi', 'body_html' => '<p>hi</p>']);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'recipient@partner.com',
    ]);

    $quotaExceeded = new GoogleServiceException(<<<'JSON'
{
  "error": {
    "code": 403,
    "message": "Quota exceeded for quota metric 'Total Query Cost' and limit 'Units per minute per user' of service 'gmail.googleapis.com'.",
    "errors": [
      {
        "message": "Quota exceeded for quota metric 'Total Query Cost' and limit 'Units per minute per user' of service 'gmail.googleapis.com'.",
        "domain": "usageLimits",
        "reason": "rateLimitExceeded"
      }
    ],
    "status": "PERMISSION_DENIED"
  }
}
JSON, 403);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('sendMessage')->once()->andThrow($quotaExceeded);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn (int $seconds): bool => $seconds >= 60 && $seconds <= 90));

    $job = new SendEmailJob($email->getKey());
    $job->setJob($queueJob);

    $job->handle(resolve(EmailSendingService::class), resolve(LinkEmailAction::class));

    expect($email->fresh()->status)->toBe(EmailStatus::SENDING)
        ->and(ProviderRateLimit::remainingSeconds((string) $this->account->getKey()))->not->toBeNull();

    Notification::assertNothingSent();
});

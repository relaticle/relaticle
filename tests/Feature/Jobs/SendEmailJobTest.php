<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Jobs\SendEmailJob;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailSendingService;

mutates(SendEmailJob::class, SyncEmailBatchCountersAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

it('records exception class and message on the email when the job fails', function (): void {
    $email = Email::create([
        'team_id' => $this->team->id,
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

it('does not mark a delivered email as failed when a later job step throws', function (): void {
    $email = Email::create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 1,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    $email = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
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
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->first();

    if (! $emailsField) {
        $this->markTestSkipped('No emails custom field seeded for this team.');
    }

    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Retry Link Person',
        'creator_id' => $this->user->id,
        'email_count' => 0,
        'outbound_email_count' => 0,
    ]);
    $person->saveCustomFieldValue($emailsField, ['recipient@partner.com'], $this->team);

    $batch = EmailBatch::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 1,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    $email = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
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
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'total_recipients' => 2,
        'sent_count' => 0,
        'failed_count' => 0,
        'status' => EmailBatchStatus::Sending,
    ]);

    Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'batch_id' => $batch->getKey(),
        'status' => EmailStatus::SENT,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::MASS_SEND,
    ]);

    $cancelled = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
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

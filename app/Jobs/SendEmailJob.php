<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Services\EmailSendingService;
use Throwable;

final class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $emailData  Validated data from SendEmailAction
     * @param  string  $accountId  ConnectedAccount ULID
     * @param  string|null  $batchId  EmailBatch ULID for mass sends
     * @param  string|null  $linkToType  Fully-qualified model class e.g. App\Models\People
     * @param  string|null  $linkToId  ULID of the record to link the sent email to
     */
    public function __construct(
        public readonly array $emailData,
        public readonly string $accountId,
        public readonly ?string $batchId = null,
        public readonly ?string $linkToType = null,
        public readonly ?string $linkToId = null,
    ) {}

    public function handle(EmailSendingService $sendingService, LinkEmailAction $linkEmailAction): void
    {
        $account = ConnectedAccount::query()->findOrFail($this->accountId);

        /** @var array{subject: string, body_html: string, to: array<array{email: string, name: string|null}>, cc: array<array{email: string, name: string|null}>, bcc: array<array{email: string, name: string|null}>, creation_source: EmailCreationSource, in_reply_to_email_id: string|null, batch_id: string|null, privacy_tier: EmailPrivacyTier} $data */
        $data = array_merge($this->emailData, ['batch_id' => $this->batchId]);
        $email = $sendingService->send($account, $data);

        // Direct-link to the CRM record the email was composed from
        if ($this->linkToType !== null && $this->linkToId !== null) {
            $alreadyLinked = DB::table('emailables')
                ->where('email_id', $email->getKey())
                ->where('emailable_type', $this->linkToType)
                ->where('emailable_id', $this->linkToId)
                ->exists();

            if (! $alreadyLinked) {
                DB::table('emailables')->insert([
                    'email_id' => $email->getKey(),
                    'emailable_type' => $this->linkToType,
                    'emailable_id' => $this->linkToId,
                    'link_source' => 'manual',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Run participant-based auto-linking (links to People/Company by email address)
        $linkEmailAction->execute($email);

        if ($this->batchId !== null) {
            EmailBatch::query()->where('id', $this->batchId)->increment('sent_count');
            $this->updateBatchStatus($this->batchId);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->batchId !== null) {
            EmailBatch::query()->where('id', $this->batchId)->increment('failed_count');
            $this->updateBatchStatus($this->batchId);
        }
    }

    private function updateBatchStatus(string $batchId): void
    {
        $batch = EmailBatch::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        $processed = $batch->sent_count + $batch->failed_count;

        if ($processed >= $batch->total_recipients) {
            $status = $batch->failed_count > 0
                ? EmailBatchStatus::PartialFailure
                : EmailBatchStatus::Completed;

            $batch->update(['status' => $status]);
        }
    }
}

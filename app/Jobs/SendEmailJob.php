<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Services\EmailSendingService;
use Throwable;

final class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $emailId,
    ) {}

    public function handle(EmailSendingService $sendingService, LinkEmailAction $linkEmailAction): void
    {
        /** @var Email|null $email */
        $email = DB::transaction(function (): ?Email {
            /** @var Email|null $lockedEmail */
            $lockedEmail = Email::query()->lockForUpdate()->find($this->emailId);

            if ($lockedEmail === null) {
                return null;
            }

            // Accept any non-terminal state. The dispatcher claims QUEUED → SENDING
            // before enqueuing, so first attempts arrive here as SENDING; Laravel
            // retries of the same job also arrive as SENDING.
            if (! in_array($lockedEmail->status, [EmailStatus::QUEUED, EmailStatus::SENDING], true)) {
                return null;
            }

            $lockedEmail->update([
                'status' => EmailStatus::SENDING,
                'attempts' => $lockedEmail->attempts + 1,
            ]);

            return $lockedEmail;
        });

        if ($email === null) {
            return;
        }

        $sent = $sendingService->send($email);

        $linkEmailAction->execute($sent);

        if ($sent->batch_id !== null) {
            EmailBatch::query()->where('id', $sent->batch_id)->increment('sent_count');
            $this->updateBatchStatus((string) $sent->batch_id);
        }
    }

    public function failed(Throwable $exception): void
    {
        /** @var Email|null $email */
        $email = Email::query()->find($this->emailId);

        if ($email === null) {
            return;
        }

        $email->update([
            'status' => EmailStatus::FAILED,
            'last_error' => $exception->getMessage(),
        ]);

        if ($email->batch_id !== null) {
            EmailBatch::query()->where('id', $email->batch_id)->increment('failed_count');
            $this->updateBatchStatus((string) $email->batch_id);
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

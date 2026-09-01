<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            if ($lockedEmail->status === EmailStatus::SENT) {
                return $lockedEmail;
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
            $existing = Email::query()->find($this->emailId);
            $this->syncBatchCounters($existing?->batch_id);

            return;
        }

        if ($email->status !== EmailStatus::SENT) {
            $email = $sendingService->send($email);
            $this->syncBatchCounters($email->batch_id);
        }

        $linkEmailAction->execute($email);

        $this->syncBatchCounters($email->batch_id);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendEmailJob failed', [
            'email_id' => $this->emailId,
            'exception' => $exception,
        ]);

        /** @var Email|null $email */
        $email = Email::query()->find($this->emailId);

        if ($email === null) {
            return;
        }

        if ($email->status !== EmailStatus::SENT) {
            $email->update([
                'status' => EmailStatus::FAILED,
                'last_error' => $exception::class.': '.$exception->getMessage(),
            ]);
        }

        $this->syncBatchCounters($email->batch_id);
    }

    /**
     * Recount sent/failed from email statuses so a crash after delivery cannot
     * leave the batch hanging, and a retry cannot double-count.
     */
    private function syncBatchCounters(?string $batchId): void
    {
        if ($batchId === null) {
            return;
        }

        DB::transaction(function () use ($batchId): void {
            $batch = EmailBatch::query()->lockForUpdate()->find($batchId);

            if ($batch === null) {
                return;
            }

            $sentCount = Email::query()
                ->where('batch_id', $batchId)
                ->where('status', EmailStatus::SENT)
                ->count();

            $failedCount = Email::query()
                ->where('batch_id', $batchId)
                ->where('status', EmailStatus::FAILED)
                ->count();

            $processed = $sentCount + $failedCount;
            $status = $batch->status;

            if ($processed >= $batch->total_recipients) {
                $status = $failedCount > 0
                    ? EmailBatchStatus::PartialFailure
                    : EmailBatchStatus::Completed;
            }

            $batch->update([
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'status' => $status,
            ]);
        });
    }
}

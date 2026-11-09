<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;

final readonly class SyncEmailBatchCountersAction
{
    /**
     * Recount sent/failed from email statuses so a crash after delivery cannot
     * leave the batch hanging, a retry cannot double-count, and cancelled
     * recipients still let the batch finish.
     */
    public function execute(?string $batchId, bool $reportFailures = false): void
    {
        if ($batchId === null) {
            return;
        }

        $finishedWithFailures = DB::transaction(function () use ($batchId, $reportFailures): ?EmailBatch {
            $batch = EmailBatch::query()->lockForUpdate()->find($batchId);

            if ($batch === null) {
                return null;
            }

            $sentCount = $this->batchEmails($batchId)->where('status', EmailStatus::SENT)->count();
            $failedCount = $this->batchEmails($batchId)->where('status', EmailStatus::FAILED)->count();
            $cancelledCount = $this->batchEmails($batchId)->where('status', EmailStatus::CANCELLED)->count();

            $processed = $sentCount + $failedCount + $cancelledCount;
            $wasNeverFinished = $batch->status === EmailBatchStatus::Queued;
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

            $finishedFirstRun = $wasNeverFinished && $status === EmailBatchStatus::PartialFailure;

            return $finishedFirstRun || ($reportFailures && $failedCount > 0) ? $batch : null;
        });

        if ($finishedWithFailures instanceof EmailBatch) {
            $this->notifySender($finishedWithFailures);
        }
    }

    /**
     * @return Builder<Email>
     */
    private function batchEmails(string $batchId): Builder
    {
        return Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->where('batch_id', $batchId);
    }

    private function notifySender(EmailBatch $batch): void
    {
        $onlyFailedSubject = $batch->failed_count === 1
            ? $this->batchEmails($batch->getKey())->where('status', EmailStatus::FAILED)->value('subject')
            : null;

        $batch->user?->notify(EmailSendFailedNotification::forMailbox(
            $batch->workspace,
            $batch->connectedAccount,
            $batch->failed_count,
            is_string($onlyFailedSubject) ? $onlyFailedSubject : null,
            EmailSendFailedNotification::batchGroupKey((string) $batch->getKey()),
            $batch->sent_count,
        ));
    }
}

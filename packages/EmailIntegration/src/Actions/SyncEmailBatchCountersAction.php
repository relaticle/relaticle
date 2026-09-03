<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;

final readonly class SyncEmailBatchCountersAction
{
    /**
     * Recount sent/failed from email statuses so a crash after delivery cannot
     * leave the batch hanging, a retry cannot double-count, and cancelled
     * recipients still let the batch finish.
     */
    public function execute(?string $batchId): void
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

            $cancelledCount = Email::query()
                ->where('batch_id', $batchId)
                ->where('status', EmailStatus::CANCELLED)
                ->count();

            $processed = $sentCount + $failedCount + $cancelledCount;
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

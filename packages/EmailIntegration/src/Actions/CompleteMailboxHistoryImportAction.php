<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

final readonly class CompleteMailboxHistoryImportAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function executeForAccount(ConnectedAccount $account): void
    {
        $account = $account->fresh() ?? $account;
        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        $this->execute((string) $account->getKey(), $batchId);
    }

    public function execute(string $accountId, string $batchId): void
    {
        DB::transaction(function () use ($accountId, $batchId): void {
            $account = ConnectedAccount::query()->lockForUpdate()->find($accountId);
            $batch = Bus::findBatch($batchId);

            if (! $account instanceof ConnectedAccount || ! $batch instanceof Batch
                || $account->history_import_batch_id !== $batchId
                || $account->sync_cursor === null
                || $batch->cancelled()
                || ! $this->importBatchHasSettled($batch, $batchId)) {
                return;
            }

            if ($account->hasCalendar() && $this->mailboxHistoryImport->isCalendarImportPending($batchId)) {
                return;
            }

            $user = $account->user;

            if ($user === null) {
                return;
            }

            $failedEmailCount = count($batch->failedJobIds);
            $failedCalendarCount = $this->mailboxHistoryImport->calendarFailureCount($batchId);
            $calendarDidNotFinish = $account->hasCalendar() && $account->calendar_sync_cursor === null;
            $hasEmailFailures = $failedEmailCount > 0;
            $hasCalendarIssues = $failedCalendarCount > 0 || $calendarDidNotFinish;

            $updates = ['initial_sync_imported' => $account->emails()->count()];

            if ($account->hasCalendar()) {
                $updates['initial_calendar_sync_imported'] = $account->meetings()->count();
            }

            if (! $hasEmailFailures && ! $hasCalendarIssues) {
                $updates['last_error'] = null;
            }

            $account->update($updates);

            if ($hasEmailFailures || $hasCalendarIssues) {
                $this->mailboxHistoryImport->markAwaitingRetrySuccessNotice($batchId);

                if ($this->alreadyNotified($user, $batchId)) {
                    return;
                }

                $this->notifyImportComplete(
                    $user,
                    $account,
                    $batchId,
                    afterRetry: false,
                    failedEmailCount: $failedEmailCount,
                    failedCalendarCount: $failedCalendarCount,
                    calendarDidNotFinish: $calendarDidNotFinish,
                );

                return;
            }

            $afterRetry = $this->mailboxHistoryImport->pullAwaitingRetrySuccessNotice($batchId);

            if ($afterRetry) {
                $this->notifyImportComplete($user, $account, $batchId, afterRetry: true);

                return;
            }

            if ($this->alreadyNotified($user, $batchId)) {
                return;
            }

            $this->notifyImportComplete($user, $account, $batchId, afterRetry: false);
        });
    }

    private function importBatchHasSettled(Batch $batch, string $batchId): bool
    {
        if ($this->mailboxHistoryImport->hasAwaitingRetrySuccessNotice($batchId) && $batch->pendingJobs > 0) {
            return false;
        }

        return ($batch->pendingJobs - count($batch->failedJobIds)) === 0;
    }

    private function alreadyNotified(User $user, string $batchId): bool
    {
        return $user->notifications()
            ->where('type', MailboxHistoryImportCompletedNotification::class)
            ->where('data->viewData->batch_id', $batchId)
            ->exists();
    }

    private function notifyImportComplete(
        User $user,
        ConnectedAccount $account,
        string $batchId,
        bool $afterRetry,
        int $failedEmailCount = 0,
        int $failedCalendarCount = 0,
        bool $calendarDidNotFinish = false,
    ): void {
        $notification = new MailboxHistoryImportCompletedNotification(
            $account,
            $batchId,
            $afterRetry,
            $failedEmailCount,
            $failedCalendarCount,
            $calendarDidNotFinish,
            $account->initial_sync_imported,
            $account->hasCalendar() ? $account->initial_calendar_sync_imported : 0,
            $account->hasCalendar(),
        );
        $user->notifyNow($notification, ['database']);

        dispatch(new SendQueuedNotifications(collect([$user]), $notification, ['mail'])->afterCommit());
    }
}

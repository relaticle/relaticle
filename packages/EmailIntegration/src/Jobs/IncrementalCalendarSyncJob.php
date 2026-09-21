<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\ReconcileCalendarMeetingsAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Exceptions\ReconcileCalendarMeetingsFailed;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

#[DeleteWhenMissingModels]
final class IncrementalCalendarSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Queueable;

    public int $tries = 3;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly bool $reconcileAfter = false,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(CalendarServiceFactoryInterface $serviceFactory): void
    {
        $account = $this->connectedAccount->fresh() ?? $this->connectedAccount;

        if (self::shouldDeferToHistoryImportRelist($account)) {
            return;
        }

        if (! $account->hasCalendar() || $account->status !== EmailAccountStatus::ACTIVE) {
            resolve(MailboxHistoryImportService::class)->completeCalendarImport($account, succeeded: false);
            resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);

            return;
        }

        if (! $account->calendar_sync_cursor) {
            dispatch(new InitialCalendarSyncJob($account, reconcileAfter: $this->reconcileAfter));

            return;
        }

        if (MailboxSyncTracker::isCalendarSyncing($account) && MailboxSyncTracker::hasCalendarRunTotal($account)) {
            $this->release(30);

            return;
        }

        resolve(MailboxHistoryImportService::class)->touchCalendarImport($account);
        $calendarSyncGeneration = MailboxSyncTracker::currentCalendarSyncGeneration($account);

        $service = $serviceFactory->make($account);

        try {
            $result = $service->fetchDelta($account->calendar_sync_cursor);
        } catch (CalendarSyncTokenExpired) {
            $account->update(['calendar_sync_cursor' => null]);
            resolve(MailboxHistoryImportService::class)->touchCalendarImport($account);
            // Expired deltas omit cancelled events. Rebuild from a full list, then
            // delete local meetings the provider no longer returns.
            dispatch(new InitialCalendarSyncJob($account, reconcileAfter: true));

            return;
        }

        // Advancing the cursor before the fetched events are stored loses any event
        // whose StoreMeetingJob exhausts its retries: the next sync starts past it and
        // it is never retried. So advance the cursor only once the batch has fully stored.
        // With no events the delta is a read-only window, so advance inline.
        if ($result->events === []) {
            self::finish($account, $result->nextSyncToken, $this->reconcileAfter, storedEvents: false);

            return;
        }

        $accountId = (string) $account->getKey();
        $nextSyncToken = $result->nextSyncToken;
        $reconcileAfter = $this->reconcileAfter;

        $jobs = array_map(
            fn (CalendarEventData $event): StoreMeetingJob => new StoreMeetingJob($account, $event, $calendarSyncGeneration),
            $result->events,
        );

        MailboxSyncTracker::setCalendarRunTotal($account, count($jobs));

        $batchId = $account->history_import_batch_id;

        if (is_string($batchId) && $batchId !== '') {
            resolve(MailboxHistoryImportService::class)->addCalendarDiscovered($batchId, count($jobs));
        }

        Bus::batch($jobs)
            ->name("Incremental calendar sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($accountId, $nextSyncToken, $reconcileAfter): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                if ($batch->failedJobs > 0) {
                    self::recordBatchFailure($account, $batch->failedJobs);

                    return;
                }

                self::finish($account, $nextSyncToken, $reconcileAfter, storedEvents: true);
            })
            ->dispatch();
    }

    private static function finish(ConnectedAccount $account, ?string $nextSyncToken, bool $reconcileAfter, bool $storedEvents): void
    {
        $account = $account->fresh() ?? $account;

        if (self::shouldDeferToHistoryImportRelist($account)) {
            return;
        }

        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
        ];

        if ($nextSyncToken !== null) {
            $update['calendar_sync_cursor'] = $nextSyncToken;
        }

        if (! resolve(MailboxHistoryImportService::class)->historyImportHasUnresolvedEmailFailures($account)) {
            $update['last_error'] = null;
        }

        $account->update($update);

        if ($reconcileAfter) {
            try {
                resolve(ReconcileCalendarMeetingsAction::class)->execute($account);
            } catch (ReconcileCalendarMeetingsFailed $exception) {
                $account->update([
                    'status' => EmailAccountStatus::ERROR,
                    'last_error' => $exception->getMessage(),
                ]);
            }
        }

        $import = resolve(MailboxHistoryImportService::class);
        $batchId = $account->history_import_batch_id;
        $keepStoreFailures = is_string($batchId) && $batchId !== '' && $import->calendarFailureCount($batchId) > 0 && ! $storedEvents;

        $import->completeCalendarImport($account, succeeded: ! $keepStoreFailures);

        resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);

        dispatch(new EnsureCalendarPushChannelJob($account));
    }

    /**
     * Keep the mailbox ACTIVE: calendar:incremental-sync dispatches for active accounts
     * only, and the sync token stays put here, so the next run refetches exactly what
     * failed. Parking it would end all future calendar sync for this mailbox.
     */
    private static function recordBatchFailure(ConnectedAccount $account, int $failedJobs): void
    {
        $account = $account->fresh() ?? $account;

        if (self::shouldDeferToHistoryImportRelist($account)) {
            return;
        }

        $account->update([
            'last_calendar_synced_at' => now(),
            'last_error' => "{$failedJobs} calendar event(s) could not be stored during sync.",
        ]);

        resolve(MailboxHistoryImportService::class)->failCalendarImport($account, $failedJobs);

        resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);
    }

    public function failed(Throwable $exception): void
    {
        $account = $this->connectedAccount->fresh() ?? $this->connectedAccount;

        if (self::shouldDeferToHistoryImportRelist($account)) {
            return;
        }

        resolve(MailboxHistoryImportService::class)->failCalendarImport($account);

        $hasHistoryImport = is_string($account->history_import_batch_id)
            && $account->history_import_batch_id !== '';

        $account->update([
            'status' => $this->isAuthError($exception)
                ? EmailAccountStatus::REAUTH_REQUIRED
                : ($hasHistoryImport ? EmailAccountStatus::ACTIVE : EmailAccountStatus::ERROR),
            'last_error' => $exception->getMessage(),
        ]);

        resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);
    }

    public function uniqueId(): string
    {
        return "incremental-calendar-sync-{$this->connectedAccount->getKey()}";
    }

    private static function shouldDeferToHistoryImportRelist(ConnectedAccount $account): bool
    {
        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '' || $account->calendar_sync_cursor !== null) {
            return false;
        }

        return resolve(MailboxHistoryImportService::class)->isCalendarImportPending($batchId);
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\ReconcileCalendarMeetingsAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Exceptions\ReconcileCalendarMeetingsFailed;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

#[DeleteWhenMissingModels]
#[Backoff(60, 300, 900)]
#[Queue('emails-sync')]
#[Tries(3)]
#[UniqueFor(3600)]
final class InitialCalendarSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Queueable;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly ?string $pageToken = null,
        public readonly bool $reconcileAfter = false,
    ) {}

    public function handle(CalendarServiceFactoryInterface $serviceFactory): void
    {
        $account = $this->connectedAccount;

        if (! $account->hasCalendar() || $account->status !== EmailAccountStatus::ACTIVE) {
            resolve(MailboxHistoryImportService::class)->completeCalendarImport($account, succeeded: false);
            resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);

            return;
        }

        $import = resolve(MailboxHistoryImportService::class);

        if ($this->pageToken === null) {
            $batchId = $account->history_import_batch_id;

            if (is_string($batchId) && $batchId !== '') {
                $import->clearCalendarFailures($batchId);
            }

            $import->touchCalendarImport($account);
        }

        $service = $serviceFactory->make($account);
        $result = $service->initialSync($this->pageToken);

        $eventsToStore = array_values($result->events);

        $reconcileAfter = $this->reconcileAfter;

        if ($eventsToStore !== []) {
            $batchId = $account->history_import_batch_id;

            if (is_string($batchId) && $batchId !== '') {
                $import->addCalendarDiscovered($batchId, count($eventsToStore));
            }

            MailboxSyncTracker::addCalendarRunTotal($account, count($eventsToStore));
        }

        if ($eventsToStore === []) {
            self::continueOrFinish($account, $result->nextPageToken, $result->nextSyncToken, $reconcileAfter);

            return;
        }

        $pageEvents = array_values(array_filter(
            $eventsToStore,
            static fn (CalendarEventData $event): bool => ! CalendarVisibility::tryFrom($event->visibility ?? '')?->isPrivate()
                && $event->status !== CalendarEventStatus::CANCELLED->value,
        ));

        $nextPageToken = $result->nextPageToken;
        $nextSyncToken = $result->nextSyncToken;

        InitialSyncPageStoreBatch::dispatchMeetings(
            account: $account,
            pageEvents: $pageEvents,
            eventsToStore: $eventsToStore,
            onPageStored: static function (ConnectedAccount $account) use ($nextPageToken, $nextSyncToken, $reconcileAfter): void {
                self::continueOrFinish($account, $nextPageToken, $nextSyncToken, $reconcileAfter);
            },
        );
    }

    public function failed(Throwable $exception): void
    {
        $account = $this->connectedAccount;
        $hasHistoryImport = is_string($account->history_import_batch_id) && $account->history_import_batch_id !== '';
        $import = resolve(MailboxHistoryImportService::class);

        if ($hasHistoryImport && $account->calendar_sync_cursor !== null) {
            $import->failCalendarImport($account);
        } else {
            $import->completeCalendarImport($account, succeeded: false);
        }

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
        return 'initial-calendar-sync-'.$this->connectedAccount->getKey().'-'.hash('xxh3', $this->pageToken ?? 'start');
    }

    private static function continueOrFinish(
        ConnectedAccount $account,
        ?string $nextPageToken,
        ?string $nextSyncToken,
        bool $reconcileAfter,
    ): void {
        $imported = Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->count();

        if ($nextPageToken !== null && $nextPageToken !== '') {
            $account->update(['initial_calendar_sync_imported' => $imported]);
            dispatch(new self($account, $nextPageToken, $reconcileAfter));

            return;
        }

        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'initial_calendar_sync_imported' => $imported,
        ];

        $import = resolve(MailboxHistoryImportService::class);

        if (! $import->historyImportHasUnresolvedEmailFailures($account)) {
            $update['last_error'] = null;
        }

        $batchId = $account->history_import_batch_id;
        $keepStoreFailures = is_string($batchId) && $batchId !== '' && $import->calendarFailureCount($batchId) > 0;

        if ($nextSyncToken !== null && $nextSyncToken !== '' && ! $keepStoreFailures) {
            $update['calendar_sync_cursor'] = $nextSyncToken;
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

        $import->completeCalendarImport(
            $account,
            succeeded: $account->calendar_sync_cursor !== null && ! $keepStoreFailures,
        );

        resolve(CompleteMailboxHistoryImportAction::class)->executeForAccount($account);

        dispatch(new EnsureCalendarPushChannelJob($account));
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Actions\ReconcileCalendarMeetingsAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Services\Exceptions\ReconcileCalendarMeetingsFailed;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

#[DeleteWhenMissingModels]
final class IncrementalCalendarSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $account = $this->connectedAccount;

        if (! $account->hasCalendar() || $account->status !== EmailAccountStatus::ACTIVE) {
            return;
        }

        if (! $account->calendar_sync_cursor) {
            dispatch(new InitialCalendarSyncJob($account));

            return;
        }

        MailboxSyncTracker::markCalendarStarted($account);

        $service = $serviceFactory->make($account);

        try {
            $result = $service->fetchDelta($account->calendar_sync_cursor);
        } catch (CalendarSyncTokenExpired) {
            MailboxSyncTracker::markCalendarFinished($account);
            $account->update(['calendar_sync_cursor' => null]);
            dispatch(new InitialCalendarSyncJob($account));

            return;
        }

        // Advancing the cursor before the fetched events are stored loses any event
        // whose StoreMeetingJob exhausts its retries: the next sync starts past it and
        // it is never retried. So advance the cursor only once the batch has fully stored.
        // With no events the delta is a read-only window, so advance inline.
        if ($result->events === []) {
            self::finish($account, $result->nextSyncToken, $this->reconcileAfter);

            return;
        }

        $accountId = (string) $account->getKey();
        $nextSyncToken = $result->nextSyncToken;
        $reconcileAfter = $this->reconcileAfter;

        $jobs = array_map(
            fn (CalendarEventData $event): StoreMeetingJob => new StoreMeetingJob($account, $event),
            $result->events,
        );

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

                self::finish($account, $nextSyncToken, $reconcileAfter);
            })
            ->dispatch();
    }

    private static function finish(ConnectedAccount $account, ?string $nextSyncToken, bool $reconcileAfter): void
    {
        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ];

        if ($nextSyncToken !== null) {
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

        MailboxSyncTracker::markCalendarFinished($account);

        dispatch(new EnsureCalendarPushChannelJob($account));
    }

    private static function recordBatchFailure(ConnectedAccount $account, int $failedJobs): void
    {
        $account->update([
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ERROR,
            'last_error' => "{$failedJobs} calendar event(s) could not be stored during sync.",
        ]);

        MailboxSyncTracker::markCalendarFinished($account);
    }

    public function failed(Throwable $exception): void
    {
        MailboxSyncTracker::markCalendarFinished($this->connectedAccount);

        $this->connectedAccount->update([
            'status' => $this->isAuthError($exception) ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "incremental-calendar-sync-{$this->connectedAccount->getKey()}";
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use Throwable;

#[DeleteWhenMissingModels]
final class InitialCalendarSyncJob implements ShouldBeUnique, ShouldQueue
{
    use DetectsAuthErrors, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> Spaced retry delays so transient 429/5xx don't hammer the provider. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly ?string $pageToken = null,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(CalendarServiceFactoryInterface $serviceFactory): void
    {
        $account = $this->connectedAccount;

        if (! $account->hasCalendar() || $account->status !== EmailAccountStatus::ACTIVE) {
            MailboxSyncTracker::markCalendarFinished($account);

            return;
        }

        if ($account->calendar_sync_cursor === null) {
            MailboxSyncTracker::markCalendarStarted($account);
        }

        $service = $serviceFactory->make($account);
        $result = $service->initialSync($this->pageToken);

        if ($result->events === []) {
            self::continueOrFinish($account, $result->nextPageToken, $result->nextSyncToken);

            return;
        }

        $nextPageToken = $result->nextPageToken;
        $nextSyncToken = $result->nextSyncToken;

        $pageEvents = array_values($result->events);

        InitialSyncPageStoreBatch::dispatchMeetings(
            account: $account,
            pageEvents: $pageEvents,
            eventsToStore: $pageEvents,
            onPageStored: static function (ConnectedAccount $account) use ($nextPageToken, $nextSyncToken): void {
                self::continueOrFinish($account, $nextPageToken, $nextSyncToken);
            },
        );
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
        return 'initial-calendar-sync-'.$this->connectedAccount->getKey().'-'.hash('xxh3', $this->pageToken ?? 'start');
    }

    private static function continueOrFinish(
        ConnectedAccount $account,
        ?string $nextPageToken,
        ?string $nextSyncToken,
    ): void {
        $imported = Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->count();

        if ($nextPageToken !== null && $nextPageToken !== '') {
            $account->update(['initial_calendar_sync_imported' => $imported]);
            dispatch(new self($account, $nextPageToken));

            return;
        }

        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
            'initial_calendar_sync_imported' => $imported,
        ];

        if ($nextSyncToken !== null && $nextSyncToken !== '') {
            $update['calendar_sync_cursor'] = $nextSyncToken;
        }

        $account->update($update);

        MailboxSyncTracker::markCalendarFinished($account);

        dispatch(new EnsureCalendarPushChannelJob($account));
    }
}

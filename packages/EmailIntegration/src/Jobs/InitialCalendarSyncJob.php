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
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\Concerns\DetectsAuthErrors;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
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
            return;
        }

        $service = $serviceFactory->make($account);
        $result = $service->initialSync($this->pageToken);

        if ($result->events === []) {
            self::continueOrFinish($account, $result->nextPageToken, $result->nextSyncToken);

            return;
        }

        $accountId = (int) $account->getKey();
        $nextPageToken = $result->nextPageToken;
        $nextSyncToken = $result->nextSyncToken;

        $jobs = array_map(
            fn (CalendarEventData $event): StoreMeetingJob => new StoreMeetingJob($account, $event),
            $result->events,
        );

        Bus::batch($jobs)
            ->name("Initial calendar sync: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->then(function () use ($accountId, $nextPageToken, $nextSyncToken): void {
                $account = ConnectedAccount::query()->whereKey($accountId)->first();

                if (! $account instanceof ConnectedAccount) {
                    return;
                }

                self::continueOrFinish($account, $nextPageToken, $nextSyncToken);
            })
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
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
        if ($nextPageToken !== null && $nextPageToken !== '') {
            dispatch(new self($account, $nextPageToken));

            return;
        }

        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ];

        if ($nextSyncToken !== null && $nextSyncToken !== '') {
            $update['calendar_sync_cursor'] = $nextSyncToken;
        }

        $account->update($update);
    }
}

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
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
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

        $service = $serviceFactory->make($account);

        try {
            $result = $service->fetchDelta($account->calendar_sync_cursor);
        } catch (CalendarSyncTokenExpired) {
            $account->update(['calendar_sync_cursor' => null]);
            dispatch(new InitialCalendarSyncJob($account));

            return;
        }

        foreach ($result->events as $event) {
            dispatch(new StoreMeetingJob($account, $event));
        }

        $update = [
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ];

        // Never overwrite a good cursor with null (see InitialCalendarSyncJob).
        if ($result->nextSyncToken !== null) {
            $update['calendar_sync_cursor'] = $result->nextSyncToken;
        }

        $account->update($update);
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
        return "incremental-calendar-sync-{$this->connectedAccount->getKey()}";
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Throwable;

final class IncrementalCalendarSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

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
            dispatch(new StoreMeetingJob($account, serialize($event)));
        }

        $account->update([
            'calendar_sync_cursor' => $result->nextSyncToken,
            'last_calendar_synced_at' => now(),
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $isAuthError = str_contains($exception->getMessage(), 'invalid_grant')
            || str_contains($exception->getMessage(), '401');

        $this->connectedAccount->update([
            'status' => $isAuthError ? EmailAccountStatus::REAUTH_REQUIRED : EmailAccountStatus::ERROR,
            'last_error' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "incremental-calendar-sync-{$this->connectedAccount->getKey()}";
    }
}

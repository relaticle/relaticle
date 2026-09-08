<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Exceptions\ReconcileCalendarMeetingsFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Throwable;

final readonly class ReconcileCalendarMeetingsAction
{
    public function __construct(
        private CalendarServiceFactoryInterface $calendarFactory,
    ) {}

    public function execute(ConnectedAccount $account): int
    {
        if (! $account->hasCalendar()) {
            return 0;
        }

        try {
            $activeIds = $this->calendarFactory->make($account)->listActiveProviderEventIds();
        } catch (Throwable $exception) {
            throw ReconcileCalendarMeetingsFailed::fromProvider($exception);
        }

        if ($activeIds === []) {
            return Meeting::query()
                ->where('connected_account_id', $account->getKey())
                ->delete();
        }

        return Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->whereNotIn('provider_event_id', $activeIds)
            ->delete();
    }
}

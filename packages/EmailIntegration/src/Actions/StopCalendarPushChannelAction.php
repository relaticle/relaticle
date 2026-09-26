<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Throwable;

final readonly class StopCalendarPushChannelAction
{
    public function __construct(
        private CalendarServiceFactoryInterface $calendarFactory,
    ) {}

    public function execute(ConnectedAccount $account): void
    {
        $channelId = $account->calendar_push_channel_id;

        if ($channelId === null || $channelId === '') {
            $this->clearPushFields($account);

            return;
        }

        if ($account->hasCalendar() && $account->status === EmailAccountStatus::ACTIVE) {
            try {
                $this->calendarFactory->make($account)->stopPushChannel(
                    $channelId,
                    $account->calendar_push_resource_id,
                );
            } catch (Throwable) {
                // Best-effort remote unsubscribe. Local state is cleared below.
            }
        }

        $this->clearPushFields($account);
    }

    private function clearPushFields(ConnectedAccount $account): void
    {
        $account->update([
            'calendar_push_channel_id' => null,
            'calendar_push_resource_id' => null,
            'calendar_push_verification_token' => null,
            'calendar_push_expires_at' => null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Support\CalendarPushWebhookUrl;

#[Description('Renew calendar push notification channels before they expire.')]
#[Signature('calendar:renew-push-channels')]
final class RenewCalendarPushChannelsCommand extends Command
{
    public function handle(): int
    {
        if (! CalendarPushWebhookUrl::isPubliclyReachable()) {
            return self::SUCCESS;
        }

        ConnectedAccount::query()
            ->where('status', EmailAccountStatus::ACTIVE)
            ->whereJsonContains('capabilities->calendar', true)
            ->whereNotNull('calendar_sync_cursor')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('calendar_push_expires_at')
                    ->orWhere('calendar_push_expires_at', '<=', now()->addDay());
            })
            ->each(function (ConnectedAccount $account): void {
                dispatch(new EnsureCalendarPushChannelJob($account));
            });

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

#[Description('Dispatch incremental calendar sync jobs for accounts with calendar enabled.')]
#[Signature('calendar:incremental-sync')]
final class IncrementalCalendarSyncCommand extends Command
{
    public function handle(): int
    {
        ConnectedAccount::query()
            ->where('status', EmailAccountStatus::ACTIVE)
            ->whereJsonContains('capabilities->calendar', true)
            ->each(function (ConnectedAccount $account): void {
                dispatch(new IncrementalCalendarSyncJob($account));
            });

        return self::SUCCESS;
    }
}

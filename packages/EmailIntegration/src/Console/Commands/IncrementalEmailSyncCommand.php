<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

#[Description('Dispatch incremental mailbox sync jobs for active accounts.')]
#[Signature('email:incremental-sync')]
final class IncrementalEmailSyncCommand extends Command
{
    public function handle(): int
    {
        ConnectedAccount::query()
            ->where('status', EmailAccountStatus::ACTIVE)
            ->whereNotNull('sync_cursor')
            ->each(function (ConnectedAccount $account): void {
                dispatch(new IncrementalEmailSyncJob($account));
            });

        return self::SUCCESS;
    }
}

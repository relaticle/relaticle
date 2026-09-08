<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

final readonly class StartMailboxHistoryImportAction
{
    public function execute(ConnectedAccount $connectedAccount): void
    {
        dispatch(new RelinkMailboxHistoryJob($connectedAccount))->afterCommit();

        if ($connectedAccount->sync_cursor !== null) {
            MailboxSyncTracker::markEmailStarted($connectedAccount);
            dispatch(new IncrementalEmailSyncJob($connectedAccount))->afterCommit();
        } else {
            dispatch(new InitialEmailSyncJob($connectedAccount))->afterCommit();
        }

        if (! $connectedAccount->hasCalendar()) {
            return;
        }

        if ($connectedAccount->calendar_sync_cursor !== null) {
            MailboxSyncTracker::markCalendarStarted($connectedAccount);
            dispatch(new IncrementalCalendarSyncJob($connectedAccount, reconcileAfter: true))->afterCommit();

            return;
        }

        dispatch(new InitialCalendarSyncJob($connectedAccount))->afterCommit();
    }
}

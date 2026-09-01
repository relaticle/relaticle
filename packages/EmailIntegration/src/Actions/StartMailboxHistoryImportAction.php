<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class StartMailboxHistoryImportAction
{
    public function execute(ConnectedAccount $connectedAccount): void
    {
        dispatch(new RelinkMailboxHistoryJob($connectedAccount))->afterCommit();
        dispatch(new InitialEmailSyncJob($connectedAccount))->afterCommit();

        if ($connectedAccount->hasCalendar()) {
            dispatch(new InitialCalendarSyncJob($connectedAccount))->afterCommit();
        }
    }
}

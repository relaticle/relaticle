<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class ConnectedAccountObserver
{
    public function created(ConnectedAccount $connectedAccount): void
    {
        dispatch(new InitialEmailSyncJob($connectedAccount))->afterCommit();
    }
}

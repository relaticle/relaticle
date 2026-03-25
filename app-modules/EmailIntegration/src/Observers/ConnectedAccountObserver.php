<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use App\Models\User;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class ConnectedAccountObserver
{
    public function creating(ConnectedAccount $connectedAccount): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $connectedAccount->user_id = $user->getKey();
            $connectedAccount->team_id = $user->currentTeam->getKey();
        }
    }

    public function created(ConnectedAccount $connectedAccount): void
    {
        InitialEmailSyncJob::dispatch($connectedAccount)->afterCommit();
    }
}

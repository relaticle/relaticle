<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Models\Workspace;
use Laravel\Jetstream\Contracts\DeletesTeams;

final readonly class DeleteWorkspace implements DeletesTeams
{
    public function __construct(private CancelWorkspaceSubscription $cancelSubscription) {}

    /**
     * Delete the given workspace.
     */
    public function delete(Workspace $workspace): void
    {
        $this->cancelSubscription->execute($workspace, immediately: true);

        $workspace->purge();
    }
}

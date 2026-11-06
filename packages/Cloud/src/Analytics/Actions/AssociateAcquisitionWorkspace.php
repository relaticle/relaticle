<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Actions;

use App\Models\Workspace;
use Relaticle\Cloud\Analytics\Models\Acquisition;

final readonly class AssociateAcquisitionWorkspace
{
    public function execute(Workspace $workspace): void
    {
        if ($workspace->owner()->first()?->ownedWorkspaces()->whereKeyNot($workspace->getKey())->exists()) {
            return;
        }

        Acquisition::query()
            ->whereKey($workspace->user_id)
            ->whereNull('workspace_linked_at')
            ->update(['workspace_id' => $workspace->getKey(), 'workspace_linked_at' => now()]);
    }
}

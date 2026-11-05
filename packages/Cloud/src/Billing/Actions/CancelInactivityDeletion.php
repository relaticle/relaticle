<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Models\Workspace;

final readonly class CancelInactivityDeletion
{
    public function execute(Workspace $workspace): void
    {
        Workspace::query()
            ->whereKey($workspace->getKey())
            ->scheduledForInactivity()
            ->update(['scheduled_deletion_at' => null, 'deletion_reason' => null]);
    }
}

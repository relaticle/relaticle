<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

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

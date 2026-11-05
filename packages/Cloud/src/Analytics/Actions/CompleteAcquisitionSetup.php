<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Actions;

use App\Models\Workspace;
use Relaticle\Cloud\Analytics\Models\Acquisition;

final readonly class CompleteAcquisitionSetup
{
    public function execute(Workspace $workspace): void
    {
        Acquisition::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereNull('setup_completed_at')
            ->update(['setup_completed_at' => now()]);
    }
}

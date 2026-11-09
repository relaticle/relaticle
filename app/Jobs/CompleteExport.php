<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Workspaces\BindWorkspace;
use Filament\Actions\Exports\Jobs\ExportCompletion;

final class CompleteExport extends ExportCompletion
{
    /** @return list<BindWorkspace> */
    public function middleware(): array
    {
        $workspaceId = $this->export->getAttribute('workspace_id');

        return is_string($workspaceId) ? [new BindWorkspace($workspaceId)] : [];
    }
}

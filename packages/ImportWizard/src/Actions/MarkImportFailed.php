<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

final readonly class MarkImportFailed
{
    public function execute(User $user, Import $import): Import
    {
        abort_unless($user->hasWorkspaceCapability((string) $import->workspace_id, WorkspaceCapability::DataImport), 403);

        $import->update(['status' => ImportStatus::Failed]);

        return $import;
    }
}

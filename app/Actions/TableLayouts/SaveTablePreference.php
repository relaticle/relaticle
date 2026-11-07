<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;

final readonly class SaveTablePreference
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, TableStateData $state, ?TableLayout $activeLayout, ?string $appliedLayoutHash): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_if($activeLayout instanceof TableLayout && ($activeLayout->entity !== $entity || $activeLayout->workspace_id !== $workspace->getKey() || $user->cannot('view', $activeLayout)), 403);

        return TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->updateOrCreate(
                ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                [
                    'state' => $state,
                    'active_layout_id' => $activeLayout?->getKey(),
                    'applied_layout_hash' => $activeLayout instanceof TableLayout ? $appliedLayoutHash : null,
                ],
            );
    }
}

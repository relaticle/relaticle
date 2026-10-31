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

final readonly class SetDefaultTableLayout
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, ?TableLayout $layout): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_if($layout instanceof TableLayout && ($layout->entity !== $entity || $layout->workspace_id !== $workspace->getKey() || $user->cannot('view', $layout)), 403);

        $preference = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->firstOrNew(['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity]);

        $preference->state ??= new TableStateData;
        $preference->default_layout_id = $layout?->getKey();
        $preference->save();

        return $preference;
    }
}

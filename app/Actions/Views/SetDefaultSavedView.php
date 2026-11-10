<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;

final readonly class SetDefaultSavedView
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, ?SavedView $view): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_if($view instanceof SavedView && ($view->entity !== $entity || $view->workspace_id !== $workspace->getKey() || $user->cannot('view', $view)), 403);

        $preference = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->firstOrCreate(
                ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                ['state' => new TableStateData],
            );
        $preference->update(['default_view_id' => $view?->getKey()]);

        return $preference;
    }
}

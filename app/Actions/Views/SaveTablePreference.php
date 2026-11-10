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
use Illuminate\Support\Facades\DB;

final readonly class SaveTablePreference
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, TableStateData $state, ?SavedView $activeView, ?string $appliedViewHash): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        if ($activeView instanceof SavedView) {
            abort_if($activeView->entity !== $entity || $activeView->workspace_id !== $workspace->getKey(), 403);
            abort_unless($user->can('view', $activeView), 403);
        }

        return DB::transaction(function () use ($user, $workspace, $entity, $state, $activeView, $appliedViewHash): TablePreference {
            $activeView = $this->stillExists($activeView) ? $activeView : null;

            return TablePreference::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->updateOrCreate(
                    ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                    [
                        'state' => $state,
                        'active_view_id' => $activeView?->getKey(),
                        'applied_view_hash' => $activeView instanceof SavedView ? $appliedViewHash : null,
                    ],
                );
        });
    }

    private function stillExists(?SavedView $view): bool
    {
        return $view instanceof SavedView
            && SavedView::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($view->getKey())->sharedLock()->exists();
    }
}

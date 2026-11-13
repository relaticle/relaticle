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
use Illuminate\Support\Facades\DB;

final readonly class SaveTablePreference
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, TableStateData $state, ?TableLayout $activeLayout, ?string $appliedLayoutHash): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        if ($activeLayout instanceof TableLayout) {
            abort_if($activeLayout->entity !== $entity || $activeLayout->workspace_id !== $workspace->getKey(), 403);
            abort_unless($user->can('view', $activeLayout), 403);
        }

        return DB::transaction(function () use ($user, $workspace, $entity, $state, $activeLayout, $appliedLayoutHash): TablePreference {
            $activeLayout = $this->stillExists($activeLayout) ? $activeLayout : null;

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
        });
    }

    private function stillExists(?TableLayout $layout): bool
    {
        return $layout instanceof TableLayout
            && TableLayout::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($layout->getKey())->sharedLock()->exists();
    }
}

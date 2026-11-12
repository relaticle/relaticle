<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class SaveBoardState
{
    /**
     * @param  array{stage_order?: list<string>, filters?: array<string, mixed>, grouping?: ?string}  $changes
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $changes, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_if($entity->boardFieldCode() === null, 403);

        if ($view instanceof SavedView) {
            abort_unless($view->entity === $entity
                && $view->workspace_id === $workspace->getKey()
                && $view->type === SavedViewType::Board
                && $user->can('update', $view), 403);
        }

        return DB::transaction(function () use ($user, $workspace, $entity, $changes, $view): BoardStateData {
            if ($view instanceof SavedView) {
                $locked = SavedView::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($view->getKey())->lockForUpdate()->firstOrFail();

                return $this->write($locked, 'state', $locked->boardState(), $changes);
            }

            $preference = TablePreference::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->firstOrCreate(
                    ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                    ['state' => new TableStateData],
                );
            $locked = TablePreference::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($preference->getKey())->lockForUpdate()->firstOrFail();

            return $this->write($locked, 'board_state', $locked->board_state, $changes);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function write(SavedView|TablePreference $store, string $column, BoardStateData $stored, array $changes): BoardStateData
    {
        $state = BoardStateData::from([...$stored->toArray(), ...$changes]);
        $store->update([$column => $state]);

        return $state;
    }
}

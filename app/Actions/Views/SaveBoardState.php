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
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class SaveBoardState
{
    public const array KEYS = [
        'stage_order', 'filters', 'grouping', 'sort', 'column_field', 'hidden_columns',
        'no_value_columns', 'card_attributes', 'show_labels', 'hide_empty', 'column_width', 'card_size',
    ];

    /**
     * @param  array<string, mixed>|Closure(BoardStateData): array<string, mixed>  $changes
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array|Closure $changes, ?SavedView $view = null): BoardStateData
    {
        if (is_array($changes)) {
            $this->assertKnownKeys($changes);
        }
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
    private function assertKnownKeys(array $changes): void
    {
        abort_if(array_diff(array_keys($changes), self::KEYS) !== [], 422);
    }

    /**
     * @param  array<string, mixed>|Closure(BoardStateData): array<string, mixed>  $changes
     */
    private function write(SavedView|TablePreference $store, string $column, BoardStateData $stored, array|Closure $changes): BoardStateData
    {
        if ($changes instanceof Closure) {
            $changes = $changes($stored);
            $this->assertKnownKeys($changes);
        }

        $state = BoardStateData::from([...$stored->toArray(), ...$changes]);
        $store->update([$column => $state]);

        return $state;
    }
}

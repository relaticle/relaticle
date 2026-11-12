<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;

final readonly class ReorderBoardStages
{
    /**
     * @param  list<mixed>  $optionIds
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $optionIds): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_unless(array_all($optionIds, static fn (mixed $id): bool => is_string($id)), 403);

        $optionIds = array_values(array_unique($optionIds));

        abort_if(array_diff($optionIds, $this->boardOptionIds($workspace, $entity)) !== [], 403);

        $preference = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->firstOrCreate(
                ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                ['state' => new TableStateData],
            );
        $preference->update(['board_state' => new BoardStateData(stageOrder: $optionIds)]);

        return $preference;
    }

    /**
     * @return list<string>
     */
    private function boardOptionIds(Workspace $workspace, CrmEntity $entity): array
    {
        $code = $entity->boardFieldCode();

        abort_if($code === null, 403);

        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

        $ids = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where($tenantKey, $workspace->getKey())
            ->whereIn('custom_field_id', CustomField::query()
                ->withoutGlobalScopes()
                ->where($tenantKey, $workspace->getKey())
                ->where('entity_type', $entity->value)
                ->where('code', $code)
                ->select('id'))
            ->pluck('id')
            ->all();

        return array_map(strval(...), array_values($ids));
    }
}

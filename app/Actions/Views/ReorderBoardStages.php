<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

final readonly class ReorderBoardStages
{
    public function __construct(private SaveBoardState $saveBoardState) {}

    /**
     * @param  list<mixed>  $optionIds
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $optionIds, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_unless(array_all($optionIds, static fn (mixed $id): bool => is_string($id)), 403);

        $optionIds = array_values(array_unique($optionIds));

        $boardOptionIds = $this->boardOptionIds($workspace, $entity);

        abort_if(array_diff($optionIds, $boardOptionIds) !== [], 403);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            fn (BoardStateData $stored): array => ['stage_order' => BoardStateData::withColumnIds($stored->stageOrder, $boardOptionIds, $optionIds)],
            $view,
        );
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
            ->withoutGlobalScope(TenantScope::class)
            ->where($tenantKey, $workspace->getKey())
            ->whereIn('custom_field_id', CustomField::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where($tenantKey, $workspace->getKey())
                ->forEntity($entity->model())
                ->where('code', $code)
                ->select('id'))
            ->pluck('id')
            ->all();

        return array_map(strval(...), array_values($ids));
    }
}

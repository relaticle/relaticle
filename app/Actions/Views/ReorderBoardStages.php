<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;

final readonly class ReorderBoardStages
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardColumnFieldsQuery $columnFields,
    ) {}

    /**
     * @param  list<mixed>  $optionIds
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $optionIds, ?SavedView $view = null, ?string $fieldCode = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_unless(array_all($optionIds, static fn (mixed $id): bool => is_string($id)), 403);

        $optionIds = array_values(array_unique($optionIds));

        $boardOptionIds = $this->columnFields->optionIds($user, $workspace, $entity, $fieldCode ?? $entity->boardFieldCode());

        abort_if($boardOptionIds === null || array_diff($optionIds, $boardOptionIds) !== [], 403);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            fn (BoardStateData $stored): array => ['stage_order' => BoardStateData::withColumnIds(
                $stored->stageOrder,
                $boardOptionIds,
                BoardStateData::reorderColumns($stored->orderStages($boardOptionIds), $optionIds),
            )],
            $view,
        );
    }
}

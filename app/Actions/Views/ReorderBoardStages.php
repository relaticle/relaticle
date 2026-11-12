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

        $boardOptionIds = $this->boardOptionIds($user, $workspace, $entity, $fieldCode ?? $entity->boardFieldCode());

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
    private function boardOptionIds(User $user, Workspace $workspace, CrmEntity $entity, ?string $code): array
    {
        $field = $code === null ? null : $this->columnFields->get($user, $workspace, $entity)->get($code);

        abort_unless($field instanceof CustomField, 403);

        return array_values($field->options
            ->map(static fn (CustomFieldOption $option): string => (string) $option->getKey())
            ->all());
    }
}

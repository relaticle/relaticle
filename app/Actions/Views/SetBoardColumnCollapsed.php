<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;

final readonly class SetBoardColumnCollapsed
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardColumnFieldsQuery $columnFields,
    ) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $fieldCode, string $optionId, bool $collapsed, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $optionIds = $this->columnFields->optionIds($user, $workspace, $entity, $fieldCode);

        abort_if($optionIds === null || ($optionId !== BoardStateData::NO_VALUE_COLUMN && ! in_array($optionId, $optionIds, true)), 403);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            function (BoardStateData $stored) use ($fieldCode, $optionIds, $optionId, $collapsed): array {
                if (! $collapsed && $stored->columnsCollapsed) {
                    return $stored->expandingColumns($fieldCode, $optionIds, $optionId);
                }

                if ($optionId === BoardStateData::NO_VALUE_COLUMN) {
                    $codes = array_values(array_diff($stored->collapsedNoValueColumns, [$fieldCode]));

                    return ['collapsed_no_value_columns' => $collapsed ? [...$codes, $fieldCode] : $codes];
                }

                $current = array_values(array_intersect($stored->collapsedColumns, $optionIds));
                $next = $collapsed
                    ? array_values(array_unique([...$current, $optionId]))
                    : array_values(array_diff($current, [$optionId]));

                return ['collapsed_columns' => BoardStateData::withColumnIds($stored->collapsedColumns, $optionIds, $next)];
            },
            $view,
        );
    }
}

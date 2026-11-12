<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;

final readonly class SetBoardColumnVisibility
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardColumnFieldsQuery $columnFields,
    ) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $fieldCode, string $optionId, bool $visible, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $optionIds = $this->columnFields->optionIds($user, $workspace, $entity, $fieldCode);

        abort_if($optionIds === null || ($optionId !== BoardStateData::NO_VALUE_COLUMN && ! in_array($optionId, $optionIds, true)), 403);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            function (BoardStateData $stored) use ($fieldCode, $optionIds, $optionId, $visible): array {
                if ($optionId === BoardStateData::NO_VALUE_COLUMN) {
                    $codes = array_values(array_diff($stored->noValueColumns, [$fieldCode]));

                    return ['no_value_columns' => $visible ? [...$codes, $fieldCode] : $codes];
                }

                $hidden = array_values(array_intersect($stored->hiddenColumns, $optionIds));
                $hidden = $visible
                    ? array_values(array_diff($hidden, [$optionId]))
                    : array_values(array_unique([...$hidden, $optionId]));

                abort_if(array_diff($optionIds, $hidden) === [], 422);

                return ['hidden_columns' => BoardStateData::withColumnIds($stored->hiddenColumns, $optionIds, $hidden)];
            },
            $view,
        );
    }
}

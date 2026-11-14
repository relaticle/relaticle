<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;

final readonly class ExpandBoardColumns
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardColumnFieldsQuery $columnFields,
    ) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $fieldCode, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $optionIds = $this->columnFields->optionIds($user, $workspace, $entity, $fieldCode);

        abort_if($optionIds === null, 403);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            fn (BoardStateData $stored): array => $stored->expandingColumns($fieldCode, $optionIds),
            $view,
        );
    }
}

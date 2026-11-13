<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;

final readonly class SetBoardColumnField
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardColumnFieldsQuery $columnFields,
    ) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, ?string $code, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        if ($code !== null) {
            abort_unless($this->columnFields->get($user, $workspace, $entity)->has($code), 403);
        }

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            ['column_field' => $code === $entity->boardFieldCode() ? null : $code],
            $view,
        );
    }
}

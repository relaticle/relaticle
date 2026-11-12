<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardCardAttributesQuery;

final readonly class SetCardAttributes
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardCardAttributesQuery $cardAttributes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $keys
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $keys, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        abort_unless(array_all($keys, static fn (mixed $key): bool => is_string($key)), 422);

        abort_if(array_diff($keys, $this->cardAttributes->get($user, $workspace, $entity)) !== [], 422);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            ['card_attributes' => array_values(array_unique($keys))],
            $view,
        );
    }
}

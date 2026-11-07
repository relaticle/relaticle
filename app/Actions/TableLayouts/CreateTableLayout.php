<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\TableLayout;
use App\Models\User;
use App\Models\Workspace;

final readonly class CreateTableLayout
{
    public function __construct(private EnsureTableLayoutNameIsFree $ensureNameIsFree) {}

    public function execute(User $user, Workspace $workspace, CrmEntity $entity, string $name, TableStateData $state): TableLayout
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        return TableLayout::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'entity' => $entity,
            'name' => $this->ensureNameIsFree->execute($user, $workspace->getKey(), $entity, $name),
            'state' => $state->withoutWidths(),
        ]);
    }
}

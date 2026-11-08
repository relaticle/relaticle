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

        $layout = new TableLayout([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'entity' => $entity,
            'state' => $state->withoutWidths(),
        ]);
        $layout->name = $this->ensureNameIsFree->execute($user, $layout, $name);
        $layout->save();

        return $layout;
    }
}

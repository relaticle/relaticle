<?php

declare(strict_types=1);

namespace App\Queries\Views;

use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;

final readonly class StoredTablePreferenceQuery
{
    public function get(User $user, Workspace $workspace, CrmEntity $entity): ?TablePreference
    {
        return TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->whereBelongsTo($user)
            ->where('entity', $entity)
            ->first();
    }
}

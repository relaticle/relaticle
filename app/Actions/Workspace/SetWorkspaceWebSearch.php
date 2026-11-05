<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;

final readonly class SetWorkspaceWebSearch
{
    public function execute(User $user, Workspace $workspace, bool $enabled): void
    {
        abort_unless($user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage), 403);

        $workspace->update(['ai_settings' => [...$workspace->ai_settings ?? [], 'web_search' => $enabled]]);
    }
}

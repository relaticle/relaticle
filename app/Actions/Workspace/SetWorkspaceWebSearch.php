<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Data\AiSettings;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;

final readonly class SetWorkspaceWebSearch
{
    public function execute(User $user, Workspace $workspace, bool $enabled): void
    {
        abort_unless($user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage), 403);

        $workspace->update(['ai_settings' => AiSettings::from([...$workspace->ai_settings->toArray(), 'web_search' => $enabled])]);
    }
}

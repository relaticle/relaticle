<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksWorkspaceWriteAccess
{
    private function canWriteInWorkspace(User $user, ?string $workspaceId): bool
    {
        return $user->belongsToWorkspaceId($workspaceId) && ! $user->isViewerOnWorkspaceId($workspaceId);
    }

    private function canCreateInCurrentWorkspace(User $user): bool
    {
        $workspace = $user->currentWorkspace;

        if ($workspace === null) {
            return false;
        }

        return $user->hasVerifiedEmail() && ! $user->isViewerOnWorkspaceId($workspace->id);
    }
}

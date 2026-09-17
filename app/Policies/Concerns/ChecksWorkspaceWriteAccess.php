<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\WorkspaceCapability;
use App\Models\User;

trait ChecksWorkspaceWriteAccess
{
    private function canViewInWorkspace(User $user, ?string $workspaceId): bool
    {
        return $user->hasWorkspaceCapability($workspaceId, WorkspaceCapability::RecordsView);
    }

    private function canViewAnyInCurrentWorkspace(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::RecordsView);
    }

    private function canWriteInWorkspace(User $user, ?string $workspaceId): bool
    {
        return $user->hasWorkspaceCapability($workspaceId, WorkspaceCapability::RecordsUpdate);
    }

    private function canDeleteInWorkspace(User $user, ?string $workspaceId): bool
    {
        return $user->hasWorkspaceCapability($workspaceId, WorkspaceCapability::RecordsDelete);
    }

    private function canDeleteInCurrentWorkspace(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::RecordsDelete);
    }

    private function canForceDeleteInWorkspace(User $user, ?string $workspaceId): bool
    {
        return $user->hasWorkspaceCapability($workspaceId, WorkspaceCapability::RecordsForceDelete);
    }

    private function canExportInCurrentWorkspace(User $user): bool
    {
        return $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::DataExport);
    }

    private function canCreateInCurrentWorkspace(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::RecordsCreate);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\WorkspaceCapability;
use App\Models\User;

trait ChecksWorkspaceCapability
{
    private function allowsInCurrentWorkspace(User $user, WorkspaceCapability $capability): bool
    {
        return $user->hasVerifiedEmail()
            && $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), $capability);
    }
}

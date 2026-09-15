<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use App\Models\Workspace;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class WorkspacePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function impersonateOwner(SystemAdministrator $admin, Workspace $workspace): bool
    {
        return $admin->role->canImpersonate() && $workspace->owner !== null;
    }

    public function delete(SystemAdministrator $admin): bool
    {
        return $admin->role->canDelete();
    }

    public function deleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canDelete();
    }

    public function restore(): bool
    {
        return true;
    }

    public function forceDelete(SystemAdministrator $admin): bool
    {
        return $admin->role->canDelete();
    }

    public function forceDeleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canDelete();
    }

    public function restoreAny(): bool
    {
        return true;
    }
}

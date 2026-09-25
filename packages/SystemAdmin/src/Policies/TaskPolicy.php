<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class TaskPolicy
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

    public function reorder(): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use Illuminate\Auth\Access\Response;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class SystemAdministratorPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(SystemAdministrator $admin): Response
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator
            ? Response::allow()
            : Response::deny('Only Super Administrators can create new system administrators.');
    }

    public function update(SystemAdministrator $admin, SystemAdministrator $systemAdmin): Response
    {
        if ($admin->role === SystemAdministratorRole::SuperAdministrator) {
            return Response::allow();
        }

        if ($admin->id === $systemAdmin->id) {
            return Response::allow();
        }

        return Response::deny('You can only edit your own account.');
    }

    public function delete(SystemAdministrator $admin, SystemAdministrator $systemAdmin): Response
    {
        if ($admin->id === $systemAdmin->id) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($admin->role === SystemAdministratorRole::SuperAdministrator) {
            return Response::allow();
        }

        return Response::deny('Only Super Administrators can delete system administrators.');
    }

    public function deleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }

    public function restore(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }

    public function forceDelete(SystemAdministrator $admin, SystemAdministrator $systemAdmin): Response
    {
        if ($admin->id === $systemAdmin->id) {
            return Response::deny('You cannot permanently delete your own account.');
        }

        return $admin->role === SystemAdministratorRole::SuperAdministrator
            ? Response::allow()
            : Response::deny('Only Super Administrators can permanently delete system administrators.');
    }

    public function forceDeleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }

    public function restoreAny(SystemAdministrator $admin): bool
    {
        return $admin->role === SystemAdministratorRole::SuperAdministrator;
    }
}

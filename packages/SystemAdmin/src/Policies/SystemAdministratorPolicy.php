<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Policies;

use Illuminate\Auth\Access\Response;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class SystemAdministratorPolicy
{
    public function viewAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff();
    }

    public function view(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff();
    }

    public function create(SystemAdministrator $admin): Response
    {
        return $admin->role->canAdministerStaff()
            ? Response::allow()
            : Response::deny('Only Super Administrators can create new system administrators.');
    }

    public function update(SystemAdministrator $admin): Response
    {
        return $admin->role->canAdministerStaff()
            ? Response::allow()
            : Response::deny('Only Super Administrators can edit system administrators.');
    }

    public function delete(SystemAdministrator $admin, SystemAdministrator $systemAdmin): Response
    {
        if ($admin->id === $systemAdmin->id) {
            return Response::deny('You cannot delete your own account.');
        }

        return $this->deleteAny($admin)
            ? Response::allow()
            : Response::deny('Only Super Administrators can delete system administrators.');
    }

    public function deleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff() && $admin->role->canDelete();
    }

    public function restore(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff();
    }

    public function forceDelete(SystemAdministrator $admin, SystemAdministrator $systemAdmin): Response
    {
        if ($admin->id === $systemAdmin->id) {
            return Response::deny('You cannot permanently delete your own account.');
        }

        return $this->forceDeleteAny($admin)
            ? Response::allow()
            : Response::deny('Only Super Administrators can permanently delete system administrators.');
    }

    public function forceDeleteAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff() && $admin->role->canDelete();
    }

    public function restoreAny(SystemAdministrator $admin): bool
    {
        return $admin->role->canAdministerStaff();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class CompanyPolicy
{
    use ChecksWorkspaceWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToWorkspaceId($company->workspace_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->canWriteInWorkspace($user, $company->workspace_id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->canWriteInWorkspace($user, $company->workspace_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->canWriteInWorkspace($user, $company->workspace_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function forceDelete(User $user): bool
    {
        return $user->hasWorkspaceRole(Filament::getTenant(), 'admin');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasWorkspaceRole(Filament::getTenant(), 'admin');
    }
}

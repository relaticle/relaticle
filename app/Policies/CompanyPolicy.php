<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class CompanyPolicy
{
    use ChecksWorkspaceCapability;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsInCurrentWorkspace($user, WorkspaceCapability::RecordsView);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasWorkspaceCapability($company->workspace_id, WorkspaceCapability::RecordsView);
    }

    public function create(User $user): bool
    {
        return $this->allowsInCurrentWorkspace($user, WorkspaceCapability::RecordsCreate);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasWorkspaceCapability($company->workspace_id, WorkspaceCapability::RecordsUpdate);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasWorkspaceCapability($company->workspace_id, WorkspaceCapability::RecordsDelete);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allowsInCurrentWorkspace($user, WorkspaceCapability::RecordsDelete);
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->hasWorkspaceCapability($company->workspace_id, WorkspaceCapability::RecordsDelete);
    }

    public function restoreAny(User $user): bool
    {
        return $this->allowsInCurrentWorkspace($user, WorkspaceCapability::RecordsDelete);
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->hasWorkspaceCapability($company->workspace_id, WorkspaceCapability::RecordsForceDelete);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::RecordsForceDelete);
    }

    public function exportAny(User $user): bool
    {
        return $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::DataExport);
    }
}

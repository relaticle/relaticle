<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class OpportunityPolicy
{
    use ChecksWorkspaceWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $user->belongsToWorkspaceId($opportunity->workspace_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->canWriteInWorkspace($user, $opportunity->workspace_id);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $this->canWriteInWorkspace($user, $opportunity->workspace_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function restore(User $user, Opportunity $opportunity): bool
    {
        return $this->canWriteInWorkspace($user, $opportunity->workspace_id);
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

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class CompanyPolicy
{
    use ChecksTeamWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToTeamId($company->team_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->canWriteInTeam($user, $company->team_id);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->canWriteInTeam($user, $company->team_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->canWriteInTeam($user, $company->team_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function forceDelete(User $user): bool
    {
        return $user->hasTeamRole(Filament::getTenant(), 'admin');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasTeamRole(Filament::getTenant(), 'admin');
    }
}

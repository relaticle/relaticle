<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\People;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamWriteAccess;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class PeoplePolicy
{
    use ChecksTeamWriteAccess;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, People $people): bool
    {
        return $user->belongsToTeamId($people->team_id);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function update(User $user, People $people): bool
    {
        return $this->canWriteInTeam($user, $people->team_id);
    }

    public function delete(User $user, People $people): bool
    {
        return $this->canWriteInTeam($user, $people->team_id);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function restore(User $user, People $people): bool
    {
        return $this->canWriteInTeam($user, $people->team_id);
    }

    public function restoreAny(User $user): bool
    {
        return $this->canCreateInCurrentTeam($user);
    }

    public function forceDelete(User $user, People $people): bool
    {
        return $user->hasTeamRoleForTeamId($people->team_id, 'admin');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasTeamRole(Filament::getTenant(), 'admin');
    }
}

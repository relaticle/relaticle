<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksTeamWriteAccess
{
    private function canWriteInTeam(User $user, ?string $teamId): bool
    {
        return $user->belongsToTeamId($teamId) && ! $user->isViewerOnTeamId($teamId);
    }

    private function canCreateInCurrentTeam(User $user): bool
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return false;
        }

        return $user->hasVerifiedEmail() && ! $user->isViewerOnTeamId($team->id);
    }
}

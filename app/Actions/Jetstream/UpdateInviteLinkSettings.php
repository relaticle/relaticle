<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final readonly class UpdateInviteLinkSettings
{
    public function update(User $user, Team $team, string $role): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        if ($role === TeamRole::Admin->value) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $team->update(['invite_link_default_role' => $role]);
    }

    public function rotate(User $user, Team $team): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $team->rotateInviteLink();
    }
}

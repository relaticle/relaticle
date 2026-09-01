<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Rules\Role;

final readonly class UpdateTeamMemberRole
{
    public function update(User $user, Team $team, string $userId, string $role): void
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        Validator::make(['role' => $role], [
            'role' => ['required', 'string', new Role],
        ])->validate();

        $membership = Membership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $userId)
            ->first();

        abort_if($membership === null, 404);

        $touchesAdminStatus = $role === TeamRole::Admin->value
            || $membership->role === TeamRole::Admin->value;

        if ($touchesAdminStatus) {
            Gate::forUser($user)->authorize('promoteToAdmin', $team);
        }

        $team->users()->updateExistingPivot($userId, ['role' => $role]);

        event(new TeamMemberUpdated($team->fresh(), $membership));
    }
}

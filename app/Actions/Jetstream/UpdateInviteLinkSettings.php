<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Jetstream\Rules\Role;

final readonly class UpdateInviteLinkSettings
{
    public function update(User $user, Team $team, string $role): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        // The link is unlimited-use and forwardable for its whole TTL, so the
        // role it grants stays below the one that can manage members.
        Validator::make(['role' => $role], [
            'role' => ['required', 'string', new Role, Rule::notIn([TeamRole::Admin->value])],
        ], [
            'role.not_in' => __('teams.validation.invite_link_role_cannot_be_admin'),
        ])->validate();

        $team->update(['invite_link_default_role' => $role]);
    }

    public function rotate(User $user, Team $team): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $team->rotateInviteLink();
    }
}

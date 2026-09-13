<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Jetstream\Rules\Role;

final readonly class UpdateInviteLinkSettings
{
    public function update(User $user, Workspace $workspace, string $role): void
    {
        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        // The link is unlimited-use and forwardable for its whole TTL, so the
        // role it grants stays below the one that can manage members.
        Validator::make(['role' => $role], [
            'role' => ['required', 'string', new Role, Rule::notIn([WorkspaceRole::Admin->value])],
        ], [
            'role.not_in' => __('workspaces.validation.invite_link_role_cannot_be_admin'),
        ])->validate();

        $workspace->update(['invite_link_default_role' => $role]);
    }

    public function rotate(User $user, Workspace $workspace): void
    {
        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        $workspace->rotateInviteLink();
    }

    public function disable(User $user, Workspace $workspace): void
    {
        Gate::forUser($user)->authorize('addWorkspaceMember', $workspace);

        $workspace->disableInviteLink();
    }
}

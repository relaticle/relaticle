<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Rules\Role;

final readonly class UpdateWorkspaceMemberRole
{
    public function update(User $user, Workspace $workspace, string $userId, string $role): void
    {
        Gate::forUser($user)->authorize('updateWorkspaceMember', $workspace);

        Validator::make(['role' => $role], [
            'role' => ['required', 'string', new Role],
        ])->validate();

        $membership = Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        abort_if($membership === null, 404);

        $touchesAdminStatus = $role === WorkspaceRole::Admin->value
            || $membership->role === WorkspaceRole::Admin->value;

        if ($touchesAdminStatus) {
            Gate::forUser($user)->authorize('promoteToAdmin', $workspace);
        }

        $workspace->users()->updateExistingPivot($userId, ['role' => $role]);

        event(new TeamMemberUpdated($workspace->fresh(), $membership));
    }
}

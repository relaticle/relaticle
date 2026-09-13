<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceMemberRemovedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Events\TeamMemberRemoved;

final readonly class RemoveWorkspaceMember implements RemovesTeamMembers
{
    /**
     * Remove the workspace member from the given workspace.
     */
    public function remove(User $user, Workspace $workspace, User $workspaceMember): void
    {
        $this->authorize($user, $workspace, $workspaceMember);

        $this->ensureUserDoesNotOwnWorkspace($workspaceMember, $workspace);

        $this->ensureAdminIsNotRemovingAnotherAdmin($user, $workspace, $workspaceMember);

        $workspace->removeUser($workspaceMember);

        event(new TeamMemberRemoved($workspace, $workspaceMember));

        $workspaceMember->notify(new WorkspaceMemberRemovedNotification($workspace));
    }

    /**
     * Authorize that the user can remove the workspace member.
     *
     * The self-removal branch below is what lets someone leave a workspace without
     * holding the removeWorkspaceMember permission, so it has to be paired with the
     * membership check: on its own it authorizes against *any* workspace, and the
     * removal notification then names a workspace the caller was never part of.
     */
    private function authorize(User $user, Workspace $workspace, User $workspaceMember): void
    {
        throw_unless($workspaceMember->belongsToWorkspace($workspace), AuthorizationException::class);

        throw_if(! Gate::forUser($user)->check('removeWorkspaceMember', $workspace) &&
            $user->id !== $workspaceMember->id, AuthorizationException::class);
    }

    /**
     * Ensure that the currently authenticated user does not own the workspace.
     */
    private function ensureUserDoesNotOwnWorkspace(User $workspaceMember, Workspace $workspace): void
    {
        /** @var User $owner */
        $owner = $workspace->owner;
        if ($workspaceMember->id === $owner->id) {
            throw ValidationException::withMessages([
                'workspace' => [__('You may not leave a workspace that you created.')],
            ])->errorBag('removeWorkspaceMember');
        }
    }

    /**
     * Only the owner may remove another Admin. An Admin removing themselves
     * (leaving the workspace) is unaffected. The self-exception in
     * authorize() already allows that regardless of role.
     */
    private function ensureAdminIsNotRemovingAnotherAdmin(User $user, Workspace $workspace, User $workspaceMember): void
    {
        if ($user->id === $workspaceMember->id) {
            return;
        }

        if ($workspaceMember->workspaceRole($workspace)?->key !== WorkspaceRole::Admin->value) {
            return;
        }

        if (Gate::forUser($user)->check('promoteToAdmin', $workspace)) {
            return;
        }

        throw ValidationException::withMessages([
            'workspace' => [__('workspaces.notifications.permission_denied.cannot_promote_to_admin')],
        ])->errorBag('removeWorkspaceMember');
    }
}

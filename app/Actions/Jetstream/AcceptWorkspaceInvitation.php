<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Jetstream;

/**
 * Callers confirm the invitation is valid and the email matches, because each
 * surface renders a mismatch differently. Deletion state and the revoked-or-
 * expired race are enforced here so no caller can report a join that did not
 * happen; both refuse with an HttpException the caller may catch.
 */
final readonly class AcceptWorkspaceInvitation
{
    public function __construct(
        private AddsTeamMembers $adder,
    ) {}

    public function execute(User $user, WorkspaceInvitation $invitation): Workspace
    {
        $workspace = $invitation->workspace;

        abort_if($user->isScheduledForDeletion(), 403, __('workspaces.accept.account_deleting'));
        abort_if($workspace->isScheduledForDeletion(), 410, __('workspaces.accept.workspace_deleting'));

        $joined = DB::transaction(function () use ($user, $workspace, $invitation): bool {
            $locked = WorkspaceInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || $locked->isExpired()) {
                // Revoked or expired between the caller's check and this lock.
                // A user who is already a member reached this branch because a
                // concurrent accept won the race, so that stays a success;
                // anyone else never joined and must not be told they did.
                $user->unsetRelation('workspaces');

                return $user->belongsToWorkspace($workspace);
            }

            if (! $user->belongsToWorkspace($workspace)) {
                $this->addMember($user, $workspace, $this->registeredRole($workspace, $locked->role));
            }

            $locked->delete();

            return true;
        });

        abort_unless($joined, 410, __('workspaces.accept.no_longer_valid'));

        $user->unsetRelation('workspaces');
        $user->switchWorkspace($workspace);

        return $workspace;
    }

    // An unregistered or absent role key fails AddWorkspaceMember's validation from
    // inside the transaction, dead-ending the invitee on a page that never completes.
    private function registeredRole(Workspace $workspace, ?string $role): string
    {
        if ($role !== null && Jetstream::findRole($role) !== null) {
            return $role;
        }

        return $workspace->invite_link_default_role;
    }

    /**
     * ApplyTenantScopes scopes User to the tenant being browsed, which is not the
     * workspace being joined, so every lookup AddsTeamMembers makes would miss.
     * Suspends only that one named scope entry and restores exactly what it was.
     */
    private function addMember(User $invitee, Workspace $workspace, ?string $role): void
    {
        $scopeName = filament()->getTenancyScopeName();
        $originalScope = $invitee->getGlobalScopes()[$scopeName] ?? null;

        if ($originalScope === null) {
            $this->attachViaOwner($invitee, $workspace, $role);

            return;
        }

        User::addGlobalScope($scopeName, fn (Builder $query): Builder => $query);

        try {
            $this->attachViaOwner($invitee, $workspace, $role);
        } finally {
            User::addGlobalScope($scopeName, $originalScope);
        }
    }

    private function attachViaOwner(User $invitee, Workspace $workspace, ?string $role): void
    {
        /** @var User $owner */
        $owner = $workspace->owner;

        $this->adder->add($owner, $workspace, $invitee->email, $role);
    }
}

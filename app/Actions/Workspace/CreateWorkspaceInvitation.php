<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;

/**
 * Proposal-pipeline adapter over the Jetstream invite action: the chat
 * approval flow calls every create action as execute($user, $data, $source),
 * while InviteWorkspaceMember speaks ($user, $workspace, $email, $role). Workspace
 * invitations carry no creation_source column, so the $source argument that
 * PendingActionService::executeCreate() passes is accepted and discarded by
 * PHP's normal extra-argument handling (see CreateCustomField for the same
 * pattern).
 */
final readonly class CreateWorkspaceInvitation
{
    public function __construct(private InviteWorkspaceMember $inviteWorkspaceMember) {}

    /**
     * @param  array{email?: mixed, role?: mixed}  $data
     */
    public function execute(User $user, array $data): WorkspaceInvitation
    {
        $workspace = $user->currentWorkspace;

        $email = (string) ($data['email'] ?? '');
        $role = is_string($data['role'] ?? null) ? $data['role'] : WorkspaceRole::Editor->value;

        return $this->inviteWorkspaceMember->invite($user, $workspace, $email, $role);
    }
}

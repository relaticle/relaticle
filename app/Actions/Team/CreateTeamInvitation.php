<?php

declare(strict_types=1);

namespace App\Actions\Team;

use App\Actions\Jetstream\InviteTeamMember;
use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * Proposal-pipeline adapter over the Jetstream invite action: the chat
 * approval flow calls every create action as execute($user, $data, $source),
 * while InviteTeamMember speaks ($user, $team, $email, $role). Team
 * invitations carry no creation_source column, so the $source argument that
 * PendingActionService::executeCreate() passes is accepted and discarded by
 * PHP's normal extra-argument handling (see CreateCustomField for the same
 * pattern).
 */
final readonly class CreateTeamInvitation
{
    public function __construct(private InviteTeamMember $inviteTeamMember) {}

    /**
     * @param  array{email?: mixed, role?: mixed}  $data
     */
    public function execute(User $user, array $data): TeamInvitation
    {
        $team = $user->currentTeam;

        $email = (string) ($data['email'] ?? '');
        $role = is_string($data['role'] ?? null) ? $data['role'] : TeamRole::Editor->value;

        return $this->inviteTeamMember->invite($user, $team, $email, $role);
    }
}

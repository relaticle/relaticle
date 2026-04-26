<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;

final readonly class RevokeTeamInvitation
{
    public function revoke(TeamInvitationModel $invitation): void
    {
        $invitation->delete();
    }
}

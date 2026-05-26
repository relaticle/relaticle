<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;

final readonly class ResendTeamInvitation
{
    public function resend(TeamInvitationModel $invitation): void
    {
        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));
    }
}

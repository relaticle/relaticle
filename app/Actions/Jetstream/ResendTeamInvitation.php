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
        // Queued for the same reason the first send is (see InviteTeamMember):
        // the resend runs on a click in the members table, so a slow provider
        // otherwise holds the request open and surfaces its own transport error
        // to the admin. afterCommit() costs nothing here, where no transaction is
        // open, and keeps the two invitation mail paths identical if one ever is.
        Mail::to($invitation->email)->queue(new TeamInvitationMail($invitation)->afterCommit());
    }
}

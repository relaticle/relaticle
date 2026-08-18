<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;

final readonly class ResendTeamInvitation
{
    public function resend(TeamInvitationModel $invitation): void
    {
        $invitation->issueToken();
        $invitation->save();

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));
    }
}

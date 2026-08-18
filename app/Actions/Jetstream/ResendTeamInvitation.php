<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Support\Facades\Mail;

final readonly class ResendTeamInvitation
{
    public function resend(TeamInvitationModel $invitation): void
    {
        $rawToken = $invitation->issueToken();
        $invitation->save();

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation, $rawToken));
    }
}

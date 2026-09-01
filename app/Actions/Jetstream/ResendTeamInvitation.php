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

        // Queued so a slow provider cannot hold the admin's request open, and
        // deferred to commit so the two invitation mail paths stay identical.
        Mail::to($invitation->email)->queue(new TeamInvitationMail($invitation, $rawToken)->afterCommit());
    }
}

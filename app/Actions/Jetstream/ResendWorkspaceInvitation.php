<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Mail\WorkspaceInvitationMail;
use App\Models\WorkspaceInvitation as WorkspaceInvitationModel;
use Illuminate\Support\Facades\Mail;

final readonly class ResendWorkspaceInvitation
{
    public function resend(WorkspaceInvitationModel $invitation): void
    {
        $rawToken = $invitation->issueToken();
        $invitation->save();

        // Queued so a slow provider cannot hold the admin's request open, and
        // deferred to commit so the two invitation mail paths stay identical.
        Mail::to($invitation->email)->queue(new WorkspaceInvitationMail($invitation, $rawToken)->afterCommit());
    }
}

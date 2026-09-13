<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Laravel\Jetstream\Jetstream;

/**
 * Encrypted on the queue: the accept token is a bearer credential and is stored
 * only as a hash, so it must not sit in clear text in the payload or in a
 * failed_jobs row that outlives the invitation.
 */
final class WorkspaceInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter?->name;
        $workspace = $this->invitation->workspace->name;

        return new Envelope(
            subject: $inviter === null
                ? __('mail.workspace_invitation.subject_without_inviter', ['workspace' => $workspace])
                : __('mail.workspace_invitation.subject', ['inviter' => $inviter, 'workspace' => $workspace]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                'acceptUrl' => route('workspace-invitations.token.accept', ['token' => $this->rawToken]),
                'inviterName' => $this->invitation->inviter?->name,
                'workspaceName' => $this->invitation->workspace->name,
                'roleName' => Jetstream::findRole($this->invitation->role)?->name,
            ],
        );
    }
}

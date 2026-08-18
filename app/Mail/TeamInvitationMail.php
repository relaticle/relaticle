<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Laravel\Jetstream\Jetstream;

final class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter?->name;
        $team = $this->invitation->team->name;

        return new Envelope(
            subject: $inviter === null
                ? __('teams.mail.invitation.subject_without_inviter', ['team' => $team])
                : __('teams.mail.invitation.subject', ['inviter' => $inviter, 'team' => $team]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.team-invitation',
            with: [
                'acceptUrl' => route('team-invitations.token.accept', ['token' => $this->rawToken]),
                'inviterName' => $this->invitation->inviter?->name,
                'teamName' => $this->invitation->team->name,
                'roleName' => Jetstream::findRole($this->invitation->role)?->name,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff(60, 300)]
final class InactiveWorkspaceDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $workspaceName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('cloud::inactivity.deleted.subject', ['workspace' => $this->workspaceName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'cloud::mail.inactive-workspace-deleted');
    }
}

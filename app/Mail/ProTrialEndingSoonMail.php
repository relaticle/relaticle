<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ProTrialEndingSoonMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Team $team,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->team->name} Pro trial ends in 3 days",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pro-trial-ending-soon',
        );
    }
}

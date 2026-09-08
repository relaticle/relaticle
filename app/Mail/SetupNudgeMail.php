<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SetupNudgeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Team $team,
        public string $stepKey,
        public string $conversationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.setup_nudge.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.setup-nudge',
            with: [
                'greetingName' => explode(' ', $this->user->name)[0],
                'teamName' => $this->team->name,
                'stepLabel' => __("filament/pages/dashboard.activation.steps.{$this->stepKey}.label"),
                'stepDescription' => __("filament/pages/dashboard.activation.steps.{$this->stepKey}.description"),
                'conversationUrl' => $this->conversationUrl,
            ],
        );
    }
}

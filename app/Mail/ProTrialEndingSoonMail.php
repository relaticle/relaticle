<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\Pages\Billing;
use App\Models\User;
use App\Models\Workspace;
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
        public Workspace $workspace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.trial_ending.subject'));
    }

    public function content(): Content
    {
        $owner = $this->workspace->owner;
        $timezone = $owner instanceof User ? $owner->effectiveTimezone() : (string) config('app.timezone');

        return new Content(
            markdown: 'mail.pro-trial-ending-soon',
            with: [
                'endsOn' => $this->workspace->trial_ends_at?->setTimezone($timezone)->format('M j, Y') ?? '',
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
                'grandfathered' => $this->workspace->hosted_free_grandfathered_at !== null,
            ],
        );
    }
}

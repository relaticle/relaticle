<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Mail;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Relaticle\Cloud\Billing\Filament\Billing;

#[DeleteWhenMissingModels]
#[Tries(3)]
#[Backoff(60, 300)]
final class InactiveWorkspaceDeletionWarningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Workspace $workspace,
        public CarbonImmutable $deletesAt,
        public CarbonImmutable $lastActiveAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('cloud::inactivity.warning.subject', [
            'workspace' => $this->workspace->name,
            'date' => $this->onDate($this->deletesAt),
        ]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'cloud::mail.inactive-workspace-deletion-warning',
            with: [
                'date' => $this->onDate($this->deletesAt),
                'since' => $this->onDate($this->lastActiveAt),
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
            ],
        );
    }

    private function onDate(CarbonImmutable $moment): string
    {
        return $moment->setTimezone($this->workspace->ownerTimezone())->format('M j, Y');
    }
}

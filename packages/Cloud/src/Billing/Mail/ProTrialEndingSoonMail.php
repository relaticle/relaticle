<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Mail;

use App\Enums\Plan;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;
use Relaticle\Cloud\Billing\Queries\TrialUsageQuery;

#[Tries(3)]
#[Backoff(60, 300)]
final class ProTrialEndingSoonMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Workspace $workspace,
        public CarbonImmutable $endsAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.trial_ending.subject', ['date' => $this->endsOn('M j')]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'cloud::mail.pro-trial-ending-soon',
            with: [
                'credits' => number_format(Plan::Pro->credits()),
                'endsOnLong' => $this->endsOn('M j, Y \a\t g:i A'),
                'endsOnShort' => $this->endsOn('M j'),
                'zone' => $this->zoneLabel(),
                'usage' => resolve(TrialUsageQuery::class)->forWorkspace($this->workspace),
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
                'grandfathered' => $this->workspace->hosted_free_grandfathered_at !== null,
                'deletesWhenInactive' => ! resolve(InactiveTrialWorkspacesQuery::class)->isExempt($this->workspace),
            ],
        );
    }

    private function endsOn(string $format): string
    {
        return $this->endsAt->setTimezone($this->workspace->ownerTimezone())->format($format);
    }

    private function zoneLabel(): string
    {
        $timezone = $this->workspace->ownerTimezone();

        return $timezone === 'UTC' ? 'UTC' : str_replace('_', ' ', Str::afterLast($timezone, '/'));
    }
}

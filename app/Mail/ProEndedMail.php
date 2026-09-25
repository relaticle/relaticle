<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\BillingStatus;
use App\Filament\Pages\Billing;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ProEndedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private function __construct(
        public Workspace $workspace,
        public BillingStatus $status,
    ) {}

    public static function afterTrial(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::TrialEnded);
    }

    public static function afterSubscription(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::SubscriptionEnded);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.pro_ended.{$this->status->value}.subject"));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pro-ended',
            with: [
                'heading' => __("mail.pro_ended.{$this->status->value}.heading", ['workspace' => $this->workspace->name]),
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
                'grandfathered' => $this->workspace->hosted_free_grandfathered_at !== null,
            ],
        );
    }
}

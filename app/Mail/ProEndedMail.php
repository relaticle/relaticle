<?php

declare(strict_types=1);

namespace App\Mail;

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
        public string $cause,
    ) {}

    public static function afterTrial(Workspace $workspace): self
    {
        return new self($workspace, 'trial');
    }

    public static function afterSubscription(Workspace $workspace): self
    {
        return new self($workspace, 'subscription');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.pro_ended.{$this->cause}.subject"));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pro-ended',
            with: [
                'heading' => __("mail.pro_ended.{$this->cause}.heading", ['workspace' => $this->workspace->name]),
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
                'grandfathered' => $this->workspace->hosted_free_grandfathered_at !== null,
            ],
        );
    }
}

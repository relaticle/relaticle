<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Mail;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Queries\TrialUsageQuery;

#[Tries(3)]
#[Backoff(60, 300)]
final class ProEndedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private function __construct(
        public Workspace $workspace,
        public BillingStatus $status,
        public ?Plan $plan = null,
        public bool $extended = false,
    ) {}

    public static function afterTrial(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::TrialEnded, extended: $workspace->trial_extended_at !== null);
    }

    public static function afterSubscription(Workspace $workspace): self
    {
        return new self($workspace, BillingStatus::SubscriptionEnded);
    }

    public static function afterManagedPlan(Workspace $workspace, Plan $plan): self
    {
        return new self($workspace, BillingStatus::SubscriptionEnded, $plan);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.pro_ended.{$this->copyKey()}.subject", $this->replacements()));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'cloud::mail.pro-ended',
            with: [
                'heading' => __("mail.pro_ended.{$this->copyKey()}.heading", $this->replacements()),
                'preheader' => $this->preheader(),
                'isTrial' => $this->isTrial(),
                'usage' => resolve(TrialUsageQuery::class)->forWorkspace($this->workspace),
                'credits' => number_format(Plan::Pro->credits()),
                'offersExtension' => $this->offersExtension(),
                'billingUrl' => Billing::getUrl(panel: 'app', tenant: $this->workspace),
                'ctaUrl' => $this->ctaUrl(),
                'ctaLabel' => $this->ctaLabel(),
                'managedPlan' => $this->plan?->marketingLabel(),
                'grandfathered' => $this->isGrandfathered(),
                'mailboxSyncPaused' => $this->workspace->hasConnectedMailbox(),
            ],
        );
    }

    private function isTrial(): bool
    {
        return in_array($this->copyKey(), ['trial_ended', 'extension_ended'], true);
    }

    private function offersExtension(): bool
    {
        return $this->copyKey() === 'trial_ended' && $this->workspace->canExtendTrial();
    }

    private function preheader(): string
    {
        if ($this->plan instanceof Plan) {
            return __('mail.pro_ended.managed_ended.preheader', ['plan' => $this->plan->marketingLabel()]);
        }

        if ($this->isGrandfathered()) {
            return __('mail.pro_ended.preheader_grandfathered');
        }

        return $this->isTrial()
            ? __("mail.pro_ended.{$this->copyKey()}.preheader", $this->replacements())
            : __('mail.pro_ended.preheader');
    }

    private function isGrandfathered(): bool
    {
        return $this->workspace->hosted_free_grandfathered_at !== null;
    }

    private function ctaUrl(): string
    {
        return $this->plan instanceof Plan
            ? route('contact', ['plan' => $this->plan->value])
            : Billing::getUrl(panel: 'app', tenant: $this->workspace);
    }

    private function ctaLabel(): string
    {
        if ($this->plan instanceof Plan) {
            return __('mail.pro_ended.managed_ended.cta');
        }

        return $this->isGrandfathered() ? __('mail.pro_ended.cta_grandfathered') : __('mail.pro_ended.cta');
    }

    private function copyKey(): string
    {
        if ($this->plan instanceof Plan) {
            return 'managed_ended';
        }

        return $this->extended ? 'extension_ended' : $this->status->value;
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        return [
            'workspace' => $this->workspace->name,
            'plan' => $this->plan?->marketingLabel() ?? '',
            'days' => (string) Workspace::TRIAL_EXTENSION_DAYS,
        ];
    }
}

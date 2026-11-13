<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Relaticle\Cloud\Marketing\Enums\ContactInquiry;

#[Tries(3)]
#[Backoff(60, 300)]
final class NewContactSubmissionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array{name: string, email: string, company?: ?string, message: string} $data */
    public function __construct(
        public array $data,
        public ?ContactInquiry $inquiry = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->data['email']],
            subject: $this->inquiry instanceof ContactInquiry
                ? __('cloud::mail.contact_submission.inquiry_subject', ['plan' => $this->inquiry->label(), 'name' => $this->data['name']])
                : __('cloud::mail.contact_submission.subject', ['name' => $this->data['name']]),
        );
    }

    public function content(): Content
    {
        $company = $this->data['company'] ?? null;

        return new Content(
            markdown: 'cloud::mail.new-contact-submission',
            with: [
                'replyUrl' => 'mailto:'.$this->data['email'].'?subject='.rawurlencode('Re: '.$this->envelope()->subject),
                'preheader' => $company === null || $company === ''
                    ? __('cloud::mail.contact_submission.preheader_without_company', ['email' => $this->data['email']])
                    : __('cloud::mail.contact_submission.preheader', ['company' => $company, 'email' => $this->data['email']]),
                'rows' => array_filter([
                    ['label' => __('cloud::mail.contact_submission.name'), 'value' => Str::squish($this->data['name'])],
                    ['label' => __('cloud::mail.contact_submission.email'), 'value' => $this->data['email']],
                    $company === null || $company === '' ? null : ['label' => __('cloud::mail.contact_submission.company'), 'value' => Str::squish($company)],
                ]),
            ],
        );
    }
}

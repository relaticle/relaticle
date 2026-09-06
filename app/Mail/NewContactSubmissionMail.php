<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NewContactSubmissionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array{name: string, email: string, company?: ?string, message: string} $data */
    public function __construct(
        public array $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->data['email']],
            subject: __('mail.contact_submission.subject', ['name' => $this->data['name']]),
        );
    }

    public function content(): Content
    {
        $company = $this->data['company'] ?? null;

        return new Content(
            markdown: 'mail.new-contact-submission',
            with: [
                'preheader' => $company === null || $company === ''
                    ? __('mail.contact_submission.preheader_without_company', ['email' => $this->data['email']])
                    : __('mail.contact_submission.preheader', ['company' => $company, 'email' => $this->data['email']]),
                'rows' => array_filter([
                    ['label' => __('mail.contact_submission.name'), 'value' => $this->data['name']],
                    ['label' => __('mail.contact_submission.email'), 'value' => $this->data['email']],
                    $company === null || $company === '' ? null : ['label' => __('mail.contact_submission.company'), 'value' => $company],
                ]),
            ],
        );
    }
}

<?php

declare(strict_types=1);

use App\Mail\NewContactSubmissionMail;

it('lists the submission fields and offers a reply link', function (): void {
    $mail = new NewContactSubmissionMail([
        'name' => 'Ana Reyes',
        'email' => 'ana@example.com',
        'company' => 'Acme',
        'message' => 'We need SSO.',
    ]);

    $mail->assertHasSubject(__('mail.contact_submission.subject', ['name' => 'Ana Reyes']));
    $mail->assertHasReplyTo('ana@example.com');
    $mail->assertSeeInHtml(__('mail.contact_submission.preheader', ['company' => 'Acme', 'email' => 'ana@example.com']));
    $mail->assertSeeInHtml('We need SSO.');
    $mail->assertSeeInHtml('href="mailto:ana@example.com"', escape: false);
    $mail->assertSeeInText(__('mail.contact_submission.email').': ana@example.com');
    $mail->assertSeeInText(__('mail.contact_submission.cta', ['name' => 'Ana Reyes']).': mailto:ana@example.com');
});

it('falls back to the email-only preheader without a company', function (): void {
    $mail = new NewContactSubmissionMail([
        'name' => 'Ana Reyes',
        'email' => 'ana@example.com',
        'company' => null,
        'message' => 'Hello',
    ]);

    $mail->assertSeeInHtml(__('mail.contact_submission.preheader_without_company', ['email' => 'ana@example.com']));
    $mail->assertDontSeeInHtml(__('mail.contact_submission.company'));
});

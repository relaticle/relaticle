<?php

declare(strict_types=1);

use App\Mail\NewContactSubmissionMail;

mutates(NewContactSubmissionMail::class);

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
    $mail->assertSeeInHtml('href="mailto:ana@example.com?subject=Re%3A%20New%20contact%3A%20Ana%20Reyes"', escape: false);
    $mail->assertSeeInText(__('mail.contact_submission.email').': ana@example.com');
    $mail->assertSeeInText(__('mail.contact_submission.cta', ['name' => 'Ana Reyes']).': mailto:ana@example.com?subject=Re%3A%20New%20contact%3A%20Ana%20Reyes');
});

it('encodes contact names in the reply subject without adding mailto parameters', function (): void {
    $mail = new NewContactSubmissionMail([
        'name' => 'Zoë & cc=other@example.com #1',
        'email' => 'zoe+crm@example.com',
        'message' => 'We would like to discuss licensing.',
    ]);

    $replyUrl = 'mailto:zoe+crm@example.com?subject=Re%3A%20New%20contact%3A%20Zo%C3%AB%20%26%20cc%3Dother%40example.com%20%231';

    $mail->assertSeeInHtml('href="'.$replyUrl.'"', escape: false);
    $mail->assertSeeInText($replyUrl);
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

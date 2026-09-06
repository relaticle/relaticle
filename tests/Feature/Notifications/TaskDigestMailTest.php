<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Mail\TaskDigestMail;
use App\Models\User;

it('renders the digest with subject, preheader, team sections, footer, and settings link', function (): void {
    config(['relaticle.company.address' => '123 Test St, Testville']);

    $user = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC']);

    $payload = new DigestPayload([
        new DigestTeamSection(
            teamName: 'Acme',
            overdue: [new DigestTaskItem('Call client', now()->subDay(), 'https://app.test/tasks?a')],
            upcoming: [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')],
        ),
    ]);

    $mail = new TaskDigestMail($user, $payload);

    $mail->assertHasSubject(__('mail.task_digest.subject', ['date' => now('UTC')->format('M j')]));
    $mail->assertSeeInHtml(__('mail.task_digest.preheader', ['overdue' => 1, 'due' => 1]));
    $mail->assertSeeInHtml(__('mail.task_digest.heading', ['name' => 'Ada']), escape: false);
    $mail->assertSeeInHtml('Acme');
    $mail->assertSeeInHtml('Call client');
    $mail->assertSeeInHtml('Send proposal');
    $mail->assertSeeInHtml('123 Test St');
    $mail->assertSeeInHtml(__('mail.footer.settings'));
    $mail->assertSeeInHtml(__('mail.footer.reason.digest'));
    $mail->assertSeeInText('Call client: https://app.test/tasks?a');
    $mail->assertSeeInText(__('mail.task_digest.cta').': ');
    $mail->assertDontSeeInText(__('mail.task_digest.preheader', ['overdue' => 1, 'due' => 1]));
});

it('never leaks a lang key into the rendered digest', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $payload = new DigestPayload([
        new DigestTeamSection('Acme', [], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]),
    ]);

    expect((new TaskDigestMail($user, $payload))->render())->not->toContain('mail.');
});

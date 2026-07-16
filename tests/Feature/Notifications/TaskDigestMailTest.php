<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Mail\TaskDigestMail;
use App\Models\User;

it('renders the digest with task titles, subject, footer, and settings link', function (): void {
    config(['relaticle.company.address' => '123 Test St, Testville']);

    $user = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace']);

    $payload = new DigestPayload([
        new DigestTeamSection(
            teamName: 'Acme',
            overdue: [new DigestTaskItem('Call client', now()->subDay(), 'https://app.test/tasks?a')],
            upcoming: [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')],
        ),
    ]);

    $mail = new TaskDigestMail($user, $payload);

    $mail->assertHasSubject('Your tasks for today');
    $mail->assertSeeInHtml('Acme');
    $mail->assertSeeInHtml('Call client');
    $mail->assertSeeInHtml('Send proposal');
    $mail->assertSeeInHtml('123 Test St');
    $mail->assertSeeInHtml('Manage notification settings');
});

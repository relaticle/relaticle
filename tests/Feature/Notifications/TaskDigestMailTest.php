<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Enums\Notifications\DigestCadence;
use App\Mail\TaskDigestMail;
use App\Models\User;

it('renders the digest with task titles, subject, and company footer', function (): void {
    config(['relaticle.company.address' => '123 Test St, Testville']);

    $user = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace']);

    $payload = new DigestPayload([
        new DigestTeamSection(
            teamName: 'Acme',
            overdue: [new DigestTaskItem('Call client', now()->subDay(), 'https://app.test/tasks?a')],
            upcoming: [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')],
        ),
    ]);

    $mail = new TaskDigestMail($user, $payload, DigestCadence::Daily);

    $mail->assertHasSubject('Your tasks for today');
    $mail->assertSeeInHtml('Acme');
    $mail->assertSeeInHtml('Call client');
    $mail->assertSeeInHtml('Send proposal');
    $mail->assertSeeInHtml('123 Test St');
});

it('uses the week-ahead subject for the weekly cadence', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $payload = new DigestPayload([
        new DigestTeamSection('Acme', [], [new DigestTaskItem('x', now()->addDay(), 'https://app.test/t')]),
    ]);

    (new TaskDigestMail($user, $payload, DigestCadence::Weekly))
        ->assertHasSubject('Your tasks for the week ahead');
});

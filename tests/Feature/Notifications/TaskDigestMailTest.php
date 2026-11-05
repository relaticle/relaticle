<?php

declare(strict_types=1);

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestWorkspaceSection;
use App\Mail\TaskDigestMail;
use App\Models\User;

it('renders the digest with subject, preheader, workspace sections, footer, and settings link', function (): void {
    config(['relaticle.company.address' => '123 Test St, Testville']);

    $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC']);

    $payload = new DigestPayload([
        new DigestWorkspaceSection(
            workspaceName: 'Acme',
            overdue: [new DigestTaskItem('Call client', now()->subDay(), 'https://app.test/tasks?a')],
            upcoming: [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')],
        ),
    ]);

    $mail = new TaskDigestMail($user, $payload);

    $mail->assertHasSubject('1 overdue, 1 due today, '.now('UTC')->format('M j'));
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
    $user = User::factory()->withPersonalWorkspace()->create();

    $payload = new DigestPayload([
        new DigestWorkspaceSection('Acme', [], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]),
    ]);

    expect((new TaskDigestMail($user, $payload))->render())->not->toContain('mail.');
});

it('carries rfc 8058 one-click unsubscribe headers pointing at the app host', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $payload = new DigestPayload([
        new DigestWorkspaceSection('Acme', [], [new DigestTaskItem('Send proposal', now(), 'https://app.test/tasks?b')]),
    ]);

    $mail = new TaskDigestMail($user, $payload);
    $headers = $mail->headers()->text;

    expect($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($headers['List-Unsubscribe'])->toStartWith('<'.rtrim((string) config('app.url'), '/').'/mail/unsubscribe/'.$user->id.'/task_digest?')
        ->and($headers['List-Unsubscribe'])->toEndWith('>')
        ->and($mail->render())->toContain(trim($headers['List-Unsubscribe'], '<>'))
        ->and($mail->render())->toContain(__('mail.footer.unsubscribe'));
});

it('leads the subject with the overdue and due-today counts', function (int $overdue, int $due, string $subject, string $timezone = 'UTC'): void {
    $this->travelTo('2026-11-05 12:00:00');

    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => $timezone]);

    $payload = new DigestPayload([
        new DigestWorkspaceSection(
            workspaceName: 'Acme',
            overdue: array_fill(0, $overdue, new DigestTaskItem('Late', now()->subDay(), 'https://app.test/tasks?a')),
            upcoming: array_fill(0, $due, new DigestTaskItem('Due', now(), 'https://app.test/tasks?b')),
        ),
    ]);

    (new TaskDigestMail($user, $payload))->assertHasSubject($subject);
})->with([
    'both' => [2, 1, '2 overdue, 1 due today, Nov 5'],
    'overdue plural' => [2, 0, '2 overdue tasks, Nov 5'],
    'overdue singular' => [1, 0, '1 overdue task, Nov 5'],
    'due singular' => [0, 1, '1 task due today, Nov 5'],
    'due plural' => [0, 3, '3 tasks due today, Nov 5'],
    'owner timezone date' => [1, 1, '1 overdue, 1 due today, Nov 6', 'Pacific/Auckland'],
]);

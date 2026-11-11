<?php

declare(strict_types=1);

use App\Data\DigestTaskItem;
use App\Data\TaskDigest;
use App\Http\Controllers\Dev\MailPreviewController;
use App\Mail\SetupNudgeMail;
use App\Mail\TaskAssignedMail;
use App\Mail\TaskDigestMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use App\Notifications\WorkspaceMemberRemovedNotification;
use App\Support\Mail\MailPreview;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

mutates(MailPreview::class, MailPreviewController::class);

it('renders every registered preview to html with the layout language attribute', function (): void {
    $preview = resolve(MailPreview::class);

    expect($preview->names())->toContain(
        'setup-nudge',
        'task-assigned',
        'task-digest',
        'workspace-invitation',
        'workspace-deletion-scheduled',
        'workspace-deletion-reminder',
        'workspace-deletion-cancelled',
        'workspace-member-removed',
        'account-deletion-scheduled',
        'account-deletion-reminder',
        'account-deletion-cancelled',
        'verify-email',
        'verify-email-change',
        'email-change-notice',
        'reset-password',
    );

    foreach ($preview->names() as $name) {
        $html = $preview->render($name);

        expect($html)->toContain('<html xmlns="http://www.w3.org/1999/xhtml" lang="en">')
            ->and($html)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }
});

it('does not expose the preview routes outside local', function (): void {
    expect(app('router')->has('dev.mail.index'))->toBeFalse();
});

it('renders names and titles people type as text, never as markdown', function (Closure $mail): void {
    $hostile = "Acme\n\n[Verify your account](https://phish.example) *now*";
    $workspace = Workspace::factory()->make(['id' => 1, 'user_id' => 1, 'name' => $hostile, 'scheduled_deletion_at' => now()->addDays(30)]);
    $owner = User::factory()->make(['id' => 1, 'name' => "[Hi](https://phish.example) Ada\n\n[Again](https://phish.example)", 'timezone' => 'UTC', 'current_workspace_id' => 1]);
    $owner->setRelation('currentWorkspace', $workspace);

    $rendered = $mail($owner, $workspace, $hostile)->render();
    $html = $rendered instanceof Htmlable ? $rendered->toHtml() : $rendered;

    expect($html)->not->toContain('href="https://phish.example"')
        ->and($html)->not->toContain('<em');
})->with([
    'setup nudge' => [fn (User $owner, Workspace $workspace): Mailable => new SetupNudgeMail($owner, $workspace, 'first_record', 'https://app.test/chat')],
    'task assigned' => [fn (User $owner, Workspace $workspace, string $hostile): Mailable => new TaskAssignedMail($hostile, 'https://app.test/tasks', $workspace->name)],
    'task digest' => [fn (User $owner, Workspace $workspace, string $hostile): Mailable => new TaskDigestMail($owner, $workspace, new TaskDigest([], [new DigestTaskItem($hostile, now(), 'https://app.test/tasks')]))],
    'workspace invitation' => [function (User $owner, Workspace $workspace): Mailable {
        $invitation = WorkspaceInvitation::factory()->make(['id' => 1, 'workspace_id' => 1, 'email' => 'sam@example.test', 'role' => 'member', 'expires_at' => now()->addDays(7)]);
        $invitation->setRelation('workspace', $workspace);
        $invitation->setRelation('inviter', $owner);

        return new WorkspaceInvitationMail($invitation, 'token');
    }],
    'workspace deletion scheduled' => [fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionScheduledNotification($workspace)->toMail($owner)],
    'workspace deletion reminder' => [fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionReminderNotification($workspace)->toMail($owner)],
    'workspace deletion cancelled' => [fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionCancelledNotification($workspace)->toMail($owner)],
    'workspace member removed' => [fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceMemberRemovedNotification($workspace)->toMail($owner)],
    'account deletion cancelled' => [fn (User $owner): MailMessage => new UserDeletionCancelledNotification($owner)->toMail($owner)],
]);

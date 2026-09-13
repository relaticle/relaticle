<?php

declare(strict_types=1);

use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

mutates(WorkspaceInvitationMail::class);

it('renders exactly one Accept Invitation CTA in the body', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();

    $rendered = (new WorkspaceInvitationMail($invitation, $rawToken))->render();

    expect(substr_count($rendered, __('mail.workspace_invitation.cta')))->toBe(1);
});

it('does not contain a Create Account button', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();

    $rendered = (new WorkspaceInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->not->toContain('Create Account');
});

it('mentions the workspace name and the expires-in phrase when expires_at is set', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(7),
    ]);
    $rawToken = Str::random(40);

    $rendered = (new WorkspaceInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->toContain('Acme Co')
        ->and($rendered)->toContain(__('mail.workspace_invitation.expiry', ['expiry' => '1 week from now']));
});

it('omits the expiry phrase when expires_at is null', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'expires_at' => null,
    ]);
    $rawToken = Str::random(40);

    $rendered = (new WorkspaceInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->not->toContain('expires');
});

it('renders an accept URL for the token route containing the raw token, not the hash', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $rendered = (new WorkspaceInvitationMail($invitation, $rawToken))->render();

    expect($rendered)->toContain(route('workspace-invitations.token.accept', ['token' => $rawToken]))
        ->and($rendered)->not->toContain((string) $invitation->token)
        ->and(WorkspaceInvitation::findByRawToken($rawToken)?->is($invitation))->toBeTrue();

    (new WorkspaceInvitationMail($invitation, $rawToken))->assertSeeInText(__('mail.workspace_invitation.cta').': '.route('workspace-invitations.token.accept', ['token' => $rawToken]));
});

it('names the inviter in the subject and body when the invitation has an inviter', function (): void {
    $owner = User::factory()->create(['name' => 'Ana Reyes']);
    $workspace = Workspace::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'inviter_id' => $owner->id,
    ]);
    $rawToken = $invitation->issueToken();

    $mail = new WorkspaceInvitationMail($invitation, $rawToken);

    expect($mail->envelope()->subject)->toBe(
        __('mail.workspace_invitation.subject', ['inviter' => 'Ana Reyes', 'workspace' => 'Acme Co'])
    )->and($mail->render())->toContain(
        __('mail.workspace_invitation.line_with_inviter', ['inviter' => 'Ana Reyes', 'workspace' => 'Acme Co', 'role' => 'Editor'])
    );
});

it('falls back to workspace-only subject and body copy when the invitation has no inviter', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
        'inviter_id' => null,
    ]);
    $rawToken = $invitation->issueToken();

    $mail = new WorkspaceInvitationMail($invitation, $rawToken);

    expect($invitation->inviter_id)->toBeNull()
        ->and($mail->envelope()->subject)->toBe(
            __('mail.workspace_invitation.subject_without_inviter', ['workspace' => 'Acme Co'])
        )
        ->and($mail->render())->toContain(
            __('mail.workspace_invitation.line', ['workspace' => 'Acme Co', 'role' => 'Editor'])
        );
});

it('keeps the raw token out of the queued payload at rest', function (): void {
    config(['queue.default' => 'database']);

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'guest@example.com',
        'role' => 'editor',
    ]);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    Mail::to($invitation->email)->queue(new WorkspaceInvitationMail($invitation, $rawToken));

    expect(DB::table('jobs')->value('payload'))->not->toContain($rawToken);
});

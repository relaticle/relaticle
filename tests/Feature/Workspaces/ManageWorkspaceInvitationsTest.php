<?php

declare(strict_types=1);

use App\Console\Commands\CleanupExpiredInvitationsCommand;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(WorkspaceInvitation::class);

test('invitation with future expires_at is not expired', function () {
    $invitation = WorkspaceInvitation::factory()->expiresIn(3)->make();

    expect($invitation->isExpired())->toBeFalse();
});

test('invitation with past expires_at is expired', function () {
    $invitation = WorkspaceInvitation::factory()->expired()->make();

    expect($invitation->isExpired())->toBeTrue();
});

test('invitation with null expires_at is expired', function () {
    $invitation = WorkspaceInvitation::factory()->withoutExpiry()->make();

    expect($invitation->isExpired())->toBeTrue();
});

test('invitation expiring exactly now is expired', function () {
    $invitation = WorkspaceInvitation::factory()->make([
        'expires_at' => now(),
    ]);

    $this->travel(1)->seconds();

    expect($invitation->isExpired())->toBeTrue();
});

test('pending invitations table shows invitations', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    WorkspaceInvitation::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'email' => 'pending@example.com',
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $user->currentWorkspace])
        ->assertSee('pending@example.com');
});

test('cleanup command purges old expired invitations', function () {
    WorkspaceInvitation::factory()->create([
        'expires_at' => now()->subDays(31),
    ]);

    WorkspaceInvitation::factory()->create([
        'expires_at' => now()->subDays(40),
    ]);

    WorkspaceInvitation::factory()->create([
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 2 expired invitation(s)')
        ->assertExitCode(0);

    expect(WorkspaceInvitation::count())->toBe(1);
});

test('cleanup command skips recently expired invitations', function () {
    WorkspaceInvitation::factory()->create([
        'expires_at' => now()->subDays(5),
    ]);

    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 0 expired invitation(s)')
        ->assertExitCode(0);

    expect(WorkspaceInvitation::count())->toBe(1);
});

test('cleanup command handles empty table', function () {
    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 0 expired invitation(s)')
        ->assertExitCode(0);
});

test('workspace owner can revoke a pending invitation', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $user->currentWorkspace])
        ->callAction(TestAction::make('revokeWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.workspace_invitation_revoked.success'));

    expect(WorkspaceInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('revoke action label reads "Revoke"', function () {
    expect(__('workspaces.actions.revoke_workspace_invitation'))->toBe('Revoke');
});

test('old cancel action name is gone', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $user->currentWorkspace])
        ->assertActionDoesNotExist(TestAction::make('cancelWorkspaceInvitation')->table($invitation->id));
});

test('resending re-issues the token and extends expiry', function (): void {
    Mail::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;

    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDay(),
    ]);

    expect($invitation->token)->toBeNull();

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id));

    $invitation->refresh();

    expect($invitation->token)->not->toBeNull()
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});

test('resending delivers the new invitation mailable with a working raw token', function (): void {
    Mail::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;

    $invitation = $workspace->workspaceInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDay(),
    ]);

    livewire(WorkspaceMembers::class, ['workspace' => $workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id));

    $invitation->refresh();

    Mail::assertQueued(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use ($invitation): bool {
        $resolved = WorkspaceInvitation::findByRawToken($mail->rawToken);
        $expectedUrl = route('workspace-invitations.token.accept', ['token' => $mail->rawToken]);

        return $mail->hasTo('legacy@example.test')
            && $mail->rawToken !== $invitation->token
            && $resolved instanceof WorkspaceInvitation
            && $resolved->is($invitation)
            && str_contains($mail->render(), $expectedUrl);
    });
});

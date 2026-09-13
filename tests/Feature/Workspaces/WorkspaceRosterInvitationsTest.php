<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Livewire\App\Workspaces\WorkspaceMembers;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

mutates(WorkspaceMembers::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function pendingInvitation(Workspace $workspace, string $email = 'pending@example.test', ?CarbonInterface $expiresAt = null): WorkspaceInvitation
{
    /** @var WorkspaceInvitation $invitation */
    $invitation = $workspace->workspaceInvitations()->create([
        'email' => $email,
        'role' => 'editor',
        'expires_at' => $expiresAt ?? now()->addDays(5),
    ]);

    return $invitation;
}

test('a pending invitation is listed', function (): void {
    pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee('pending@example.test');
});

test('the roster carries no invite badge when nobody is invited', function (): void {
    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertDontSee(__('workspaces.table.invite_pending'));
});

test('an invitation row is badged as pending', function (): void {
    pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee(__('workspaces.table.invite_pending'));
});

test('an invitation row shows its expiry', function (): void {
    $this->travelTo(now());

    $invitation = pendingInvitation($this->workspace, expiresAt: now()->addDays(3));

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee(__('workspaces.table.expires_in', [
            'time' => $invitation->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
        ]));
});

test('an already-expired invitation is badged as expired, not shown as a raw past date', function (): void {
    pendingInvitation($this->workspace, 'expired@example.test', now()->subDay());

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee(__('workspaces.table.invite_expired'))
        ->assertDontSee(__('workspaces.table.invite_pending'));
});

test('an expired invitation dates the lapse rather than promising a future expiry', function (): void {
    $this->travelTo(now());

    $invitation = pendingInvitation($this->workspace, 'expired@example.test', now()->subDays(3));

    $elapsed = $invitation->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSee(__('workspaces.table.expired_ago', ['time' => $elapsed]))
        ->assertDontSee(__('workspaces.table.expires_in', ['time' => $elapsed]));
});

test('a pending invitation can be revoked', function (): void {
    $invitation = pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('revokeWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.workspace_invitation_revoked.success'));

    expect($this->workspace->fresh()->workspaceInvitations)->toHaveCount(0);
});

test('resending the same invitation twice inside the window is throttled', function (): void {
    Mail::fake();

    $invitation = pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id))
        ->assertNotified(__('workspaces.notifications.workspace_invitation_sent.success'));

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->callAction(TestAction::make('resendWorkspaceInvitation')->table($invitation->id));

    Mail::assertQueuedCount(1);
});

test('no per-invitation copy link action is offered', function (): void {
    $invitation = pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertActionDoesNotExist(TestAction::make('copyInviteLink')->table($invitation->id));
});

test('a viewer cannot resend or revoke an invitation', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $this->actingAs($viewer);

    $invitation = pendingInvitation($this->workspace);

    livewire(WorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertTableActionHidden('resendWorkspaceInvitation', $invitation->id)
        ->assertTableActionHidden('revokeWorkspaceInvitation', $invitation->id);
});

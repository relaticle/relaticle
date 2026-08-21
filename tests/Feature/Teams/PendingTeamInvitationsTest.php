<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

mutates(PendingTeamInvitations::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

function pendingInvitation(Team $team, string $email = 'pending@example.test', ?CarbonInterface $expiresAt = null): TeamInvitation
{
    /** @var TeamInvitation $invitation */
    $invitation = $team->teamInvitations()->create([
        'email' => $email,
        'role' => 'editor',
        'expires_at' => $expiresAt ?? now()->addDays(5),
    ]);

    return $invitation;
}

test('a pending invitation is listed', function (): void {
    pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertSee('pending@example.test');
});

test('the card renders nothing when there are no pending invitations', function (): void {
    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertDontSee(__('teams.sections.pending_team_invitations.title'));
});

test('the card appears once an invitation exists', function (): void {
    pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertSee(__('teams.sections.pending_team_invitations.title'));
});

test('an invitation row shows its expiry', function (): void {
    $this->travelTo(now());

    $invitation = pendingInvitation($this->team, expiresAt: now()->addDays(3));

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertTableColumnFormattedStateSet(
            'expires_at',
            __('teams.table.expires_in', ['time' => $invitation->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]),
            $invitation->id,
        );
});

test('an already-expired invitation shows an expired label, not a raw past date', function (): void {
    $invitation = pendingInvitation($this->team, 'expired@example.test', now()->subDay());

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertTableColumnFormattedStateSet('expires_at', __('teams.table.expired'), $invitation->id);
});

test('a pending invitation can be revoked', function (): void {
    $invitation = pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('resending the same invitation twice inside the window is throttled', function (): void {
    Mail::fake();

    $invitation = pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_sent.success'));

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation->id));

    Mail::assertQueuedCount(1);
});

/**
 * There is deliberately no per-invitation copy-link action: the stored token is
 * a SHA-256 hash, so the only way to produce a link for an existing invitation
 * would be to re-mint and silently invalidate the one already in the invitee's
 * inbox. Twenty, Slack, and Notion all omit it for the same reason and point
 * admins at the workspace-wide invite link instead.
 */
test('no per-invitation copy link action is offered', function (): void {
    $invitation = pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertActionDoesNotExist(TestAction::make('copyInviteLink')->table($invitation->id));
});

test('a viewer cannot resend or revoke an invitation', function (): void {
    $viewer = User::factory()->create();
    $this->team->users()->attach($viewer, ['role' => TeamRole::Viewer->value]);
    $this->actingAs($viewer);

    $invitation = pendingInvitation($this->team);

    livewire(PendingTeamInvitations::class, ['team' => $this->team])
        ->assertTableActionHidden('resendTeamInvitation', $invitation->id)
        ->assertTableActionHidden('revokeTeamInvitation', $invitation->id);
});

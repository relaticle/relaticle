<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

mutates(TeamMembers::class);

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

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee('pending@example.test');
});

test('the roster carries no invite badge when nobody is invited', function (): void {
    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertDontSee(__('teams.table.invite_pending'));
});

test('an invitation row is badged as pending', function (): void {
    pendingInvitation($this->team);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee(__('teams.table.invite_pending'));
});

test('an invitation row shows its expiry', function (): void {
    $this->travelTo(now());

    $invitation = pendingInvitation($this->team, expiresAt: now()->addDays(3));

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee(__('teams.table.expires_in', [
            'time' => $invitation->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
        ]));
});

test('an already-expired invitation is badged as expired, not shown as a raw past date', function (): void {
    pendingInvitation($this->team, 'expired@example.test', now()->subDay());

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee(__('teams.table.invite_expired'))
        ->assertDontSee(__('teams.table.invite_pending'));
});

test('an expired invitation dates the lapse rather than promising a future expiry', function (): void {
    $this->travelTo(now());

    $invitation = pendingInvitation($this->team, 'expired@example.test', now()->subDays(3));

    $elapsed = $invitation->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertSee(__('teams.table.expired_ago', ['time' => $elapsed]))
        ->assertDontSee(__('teams.table.expires_in', ['time' => $elapsed]));
});

test('a pending invitation can be revoked', function (): void {
    $invitation = pendingInvitation($this->team);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect($this->team->fresh()->teamInvitations)->toHaveCount(0);
});

test('resending the same invitation twice inside the window is throttled', function (): void {
    Mail::fake();

    $invitation = pendingInvitation($this->team);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation->id))
        ->assertNotified(__('teams.notifications.team_invitation_sent.success'));

    livewire(TeamMembers::class, ['team' => $this->team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation->id));

    Mail::assertQueuedCount(1);
});

test('no per-invitation copy link action is offered', function (): void {
    $invitation = pendingInvitation($this->team);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertActionDoesNotExist(TestAction::make('copyInviteLink')->table($invitation->id));
});

test('a viewer cannot resend or revoke an invitation', function (): void {
    $viewer = User::factory()->create();
    $this->team->users()->attach($viewer, ['role' => TeamRole::Viewer->value]);
    $this->actingAs($viewer);

    $invitation = pendingInvitation($this->team);

    livewire(TeamMembers::class, ['team' => $this->team])
        ->assertTableActionHidden('resendTeamInvitation', $invitation->id)
        ->assertTableActionHidden('revokeTeamInvitation', $invitation->id);
});

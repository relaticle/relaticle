<?php

declare(strict_types=1);

use App\Console\Commands\CleanupExpiredInvitationsCommand;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(TeamInvitation::class);

// --- Model: isExpired() ---

test('invitation with future expires_at is not expired', function () {
    $invitation = TeamInvitation::factory()->expiresIn(3)->make();

    expect($invitation->isExpired())->toBeFalse();
});

test('invitation with past expires_at is expired', function () {
    $invitation = TeamInvitation::factory()->expired()->make();

    expect($invitation->isExpired())->toBeTrue();
});

test('invitation with null expires_at is expired', function () {
    $invitation = TeamInvitation::factory()->withoutExpiry()->make();

    expect($invitation->isExpired())->toBeTrue();
});

test('invitation expiring exactly now is expired', function () {
    $invitation = TeamInvitation::factory()->make([
        'expires_at' => now(),
    ]);

    $this->travel(1)->seconds();

    expect($invitation->isExpired())->toBeTrue();
});

// --- Copy invite link ---

test('team owner can copy invite link', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $user->currentTeam->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $user->currentTeam])
        ->callAction(TestAction::make('copyInviteLink')->table($invitation))
        ->assertNotified();
});

// --- Pending invitations table ---

test('pending invitations table shows invitations', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $user->currentTeam->id,
        'email' => 'pending@example.com',
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $user->currentTeam])
        ->assertCanSeeTableRecords([$invitation]);
});

// --- Cleanup command ---

test('cleanup command purges old expired invitations', function () {
    TeamInvitation::factory()->create([
        'expires_at' => now()->subDays(31),
    ]);

    TeamInvitation::factory()->create([
        'expires_at' => now()->subDays(40),
    ]);

    TeamInvitation::factory()->create([
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 2 expired invitation(s)')
        ->assertExitCode(0);

    expect(TeamInvitation::count())->toBe(1);
});

test('cleanup command skips recently expired invitations', function () {
    TeamInvitation::factory()->create([
        'expires_at' => now()->subDays(5),
    ]);

    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 0 expired invitation(s)')
        ->assertExitCode(0);

    expect(TeamInvitation::count())->toBe(1);
});

test('cleanup command handles empty table', function () {
    $this->artisan(CleanupExpiredInvitationsCommand::class)
        ->expectsOutputToContain('Purged 0 expired invitation(s)')
        ->assertExitCode(0);
});

// --- Revoke invitation (renamed from cancel) ---

test('team owner can revoke a pending invitation', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $user->currentTeam->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $user->currentTeam])
        ->callAction(TestAction::make('revokeTeamInvitation')->table($invitation))
        ->assertNotified(__('teams.notifications.team_invitation_revoked.success'));

    expect(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('revoke action label reads "Revoke"', function () {
    expect(__('teams.actions.revoke_team_invitation'))->toBe('Revoke');
});

test('old cancel action name is gone', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $user->currentTeam->id,
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $user->currentTeam])
        ->assertActionDoesNotExist(TestAction::make('cancelTeamInvitation')->table($invitation));
});

// --- Resend invitation ---

test('resending re-issues the token and extends expiry', function (): void {
    Mail::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    $team = $user->currentTeam;

    $invitation = $team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDay(),
    ]);

    expect($invitation->token)->toBeNull();

    livewire(PendingTeamInvitations::class, ['team' => $team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation));

    $invitation->refresh();

    expect($invitation->token)->not->toBeNull()
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();
});

test('resending delivers the new invitation mailable with a working raw token', function (): void {
    Mail::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    $team = $user->currentTeam;

    $invitation = $team->teamInvitations()->create([
        'email' => 'legacy@example.test',
        'role' => 'editor',
        'expires_at' => now()->addDay(),
    ]);

    livewire(PendingTeamInvitations::class, ['team' => $team])
        ->callAction(TestAction::make('resendTeamInvitation')->table($invitation));

    $invitation->refresh();

    Mail::assertQueued(TeamInvitationMail::class, function (TeamInvitationMail $mail) use ($invitation): bool {
        $resolved = TeamInvitation::findByRawToken($mail->rawToken);
        $expectedUrl = route('team-invitations.token.accept', ['token' => $mail->rawToken]);

        return $mail->hasTo('legacy@example.test')
            && $mail->rawToken !== $invitation->token
            && $resolved instanceof TeamInvitation
            && $resolved->is($invitation)
            && str_contains($mail->render(), $expectedUrl);
    });
});

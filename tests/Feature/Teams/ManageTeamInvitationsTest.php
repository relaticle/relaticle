<?php

declare(strict_types=1);

use App\Console\Commands\CleanupExpiredInvitationsCommand;
use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

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

// --- Extend invitation ---

test('team owner can extend an invitation', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $user->currentTeam->id,
    ]);

    expect($invitation->isExpired())->toBeTrue();

    livewire(PendingTeamInvitations::class, ['team' => $user->currentTeam])
        ->callAction(TestAction::make('extendTeamInvitation')->table($invitation));

    $invitation->refresh();

    expect($invitation->isExpired())->toBeFalse()
        ->and($invitation->expires_at->isFuture())->toBeTrue();
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

<?php

declare(strict_types=1);

use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\User;

mutates(PendingTeamInvitations::class);

it('prevents admin of team A from revoking an invitation belonging to team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimInvitation = $victimTeam->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
    ]);

    $this->actingAs($attacker);

    livewire(PendingTeamInvitations::class, ['team' => $attackerTeam])
        ->call('revokeTeamInvitation', $victimInvitation)
        ->assertForbidden();

    expect($victimTeam->teamInvitations()->whereKey($victimInvitation->id)->exists())
        ->toBeTrue();
});

it('prevents admin of team A from resending an invitation belonging to team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimInvitation = $victimOwner->personalTeam()->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
    ]);

    $this->actingAs($attacker);

    livewire(PendingTeamInvitations::class, ['team' => $attackerTeam])
        ->call('resendTeamInvitation', $victimInvitation)
        ->assertForbidden();
});

it('prevents admin of team A from copying an invite link for team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimInvitation = $victimOwner->personalTeam()->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
    ]);

    $this->actingAs($attacker);

    livewire(PendingTeamInvitations::class, ['team' => $attackerTeam])
        ->call('copyInviteLink', $victimInvitation)
        ->assertForbidden();
});

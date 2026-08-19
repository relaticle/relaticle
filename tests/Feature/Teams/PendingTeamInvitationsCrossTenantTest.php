<?php

declare(strict_types=1);

use App\Livewire\App\Teams\PendingTeamInvitations;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(PendingTeamInvitations::class);

it('prevents an admin of team A from revoking an invitation belonging to team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimInvitation = $victimTeam->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    $component = livewire(PendingTeamInvitations::class, ['team' => $attackerTeam]);

    $component->assertDontSee('bystander@example.com');

    rescue(fn () => $component->callAction(
        TestAction::make('revokeTeamInvitation')->table($victimInvitation->id)
    ));

    expect($victimTeam->teamInvitations()->whereKey($victimInvitation->id)->exists())->toBeTrue();
});

it('prevents an admin of team A from resending an invitation belonging to team B', function (): void {
    Mail::fake();

    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimInvitation = $victimOwner->personalTeam()->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(PendingTeamInvitations::class, ['team' => $attackerTeam])
        ->callAction(TestAction::make('resendTeamInvitation')->table($victimInvitation->id)));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

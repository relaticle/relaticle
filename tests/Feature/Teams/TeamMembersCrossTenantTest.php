<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(TeamMembers::class);

it('prevents an admin of team A from removing a member of team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimMember = User::factory()->create();
    $victimTeam->users()->attach($victimMember, ['role' => TeamRole::Editor->value]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(TeamMembers::class, ['team' => $attacker->personalTeam()])
        ->callAction(TestAction::make('removeTeamMember')->table($victimMember->id)));

    expect($victimMember->fresh()->belongsToTeam($victimTeam))->toBeTrue();
});

it('prevents an admin of team A from changing the role of a member of team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimMember = User::factory()->create();
    $victimTeam->users()->attach($victimMember, ['role' => TeamRole::Editor->value]);

    $this->actingAs($attacker);

    rescue(fn () => livewire(TeamMembers::class, ['team' => $attacker->personalTeam()])
        ->callAction(TestAction::make('updateTeamRole')->table($victimMember->id), ['role' => TeamRole::Viewer->value]));

    expect($victimMember->fresh()->teamRole($victimTeam)->key)->toBe(TeamRole::Editor->value);
});

it('does not list members of another team', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimMember = User::factory()->create();
    $victimOwner->personalTeam()->users()->attach($victimMember, ['role' => TeamRole::Editor->value]);

    $this->actingAs($attacker);

    livewire(TeamMembers::class, ['team' => $attacker->personalTeam()])
        ->assertDontSee($victimMember->email)
        ->assertDontSee($victimOwner->email);
});

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

    $component = livewire(TeamMembers::class, ['team' => $attackerTeam]);

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

    rescue(fn () => livewire(TeamMembers::class, ['team' => $attackerTeam])
        ->callAction(TestAction::make('resendTeamInvitation')->table($victimInvitation->id)));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

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

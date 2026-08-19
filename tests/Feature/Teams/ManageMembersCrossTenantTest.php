<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\ManageMembers;
use App\Models\Membership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

mutates(ManageMembers::class);

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

    $component = livewire(ManageMembers::class, ['team' => $attackerTeam]);

    $component->assertDontSee('bystander@example.com');

    rescue(fn () => $component->callAction(
        TestAction::make('revokeTeamInvitation')->table('invite:'.$victimInvitation->id)
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

    rescue(fn () => livewire(ManageMembers::class, ['team' => $attackerTeam])
        ->callAction(TestAction::make('resendTeamInvitation')->table('invite:'.$victimInvitation->id)));

    Mail::assertNothingSent();
});

it('prevents an admin of team A from copying an invite link for team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $attackerTeam = $attacker->personalTeam();

    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimInvitation = $victimOwner->personalTeam()->teamInvitations()->create([
        'email' => 'bystander@example.com',
        'role' => 'editor',
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($attacker);

    $component = livewire(ManageMembers::class, ['team' => $attackerTeam]);

    rescue(fn () => $component->callAction(
        TestAction::make('copyInviteLink')->table('invite:'.$victimInvitation->id)
    ));

    $component->assertNotNotified(__('teams.notifications.invite_link_copied.success'));
});

it('prevents an admin of team A from removing a member of team B', function (): void {
    $attacker = User::factory()->withPersonalTeam()->create();
    $victimOwner = User::factory()->withPersonalTeam()->create();
    $victimTeam = $victimOwner->personalTeam();

    $victimMember = User::factory()->create();
    $victimTeam->users()->attach($victimMember, ['role' => TeamRole::Editor->value]);

    // Team::users() never calls withPivot('id'), so the pivot accessor's own
    // `id` is always null — read the real team_user.id off Membership instead.
    $membershipId = Membership::query()
        ->where('team_id', $victimTeam->id)
        ->where('user_id', $victimMember->id)
        ->firstOrFail()
        ->id;

    $this->actingAs($attacker);

    rescue(fn () => livewire(ManageMembers::class, ['team' => $attacker->personalTeam()])
        ->callAction(TestAction::make('removeTeamMember')->table('member:'.$membershipId)));

    expect($victimMember->fresh()->belongsToTeam($victimTeam))->toBeTrue();
});

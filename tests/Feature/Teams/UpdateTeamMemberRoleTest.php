<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Livewire\App\Teams\TeamMembers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

mutates(User::class, TeamMembers::class);

test('team member roles can be updated', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('managingRoleFor', $otherUser)
        ->set('currentRole', 'editor')
        ->call('updateRole');

    expect($otherUser->fresh()->hasTeamRole(
        $user->currentTeam->fresh(), 'editor'
    ))->toBeTrue();
});

test('editor cannot update team member roles', function () {
    $user = User::factory()->withTeam()->create();

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'editor']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('managingRoleFor', $otherUser)
        ->set('currentRole', 'admin')
        ->call('updateRole')
        ->assertStatus(403);

    expect($otherUser->fresh()->hasTeamRole(
        $user->currentTeam->fresh(), 'editor'
    ))->toBeTrue();
});

test('admin cannot promote another member to admin', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $admin = User::factory()->create();
    $team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $editor = User::factory()->create();
    $team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    $this->actingAs($admin);
    Filament::setTenant($team);

    livewire(TeamMembers::class, ['team' => $team])
        ->callAction(TestAction::make('updateTeamRole')->table($editor->id), data: [
            'role' => TeamRole::Admin->value,
        ])
        ->assertHasActionErrors(['role']);

    expect($editor->fresh()->hasTeamRole($team->fresh(), TeamRole::Editor->value))->toBeTrue();
});

test('admin cannot demote another admin', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $adminA = User::factory()->create();
    $team->users()->attach($adminA, ['role' => TeamRole::Admin->value]);

    $adminB = User::factory()->create();
    $team->users()->attach($adminB, ['role' => TeamRole::Admin->value]);

    $this->actingAs($adminA);
    Filament::setTenant($team);

    livewire(TeamMembers::class, ['team' => $team])
        ->callAction(TestAction::make('updateTeamRole')->table($adminB->id), data: [
            'role' => TeamRole::Editor->value,
        ])
        ->assertHasActionErrors(['role']);

    expect($adminB->fresh()->hasTeamRole($team->fresh(), TeamRole::Admin->value))->toBeTrue();
});

test('viewer is a registered team role with only read ability', function (): void {
    $role = Jetstream::findRole(TeamRole::Viewer->value);

    expect($role)->not->toBeNull()
        ->and($role->key)->toBe('viewer')
        ->and($role->permissions)->toBe(['read']);
});

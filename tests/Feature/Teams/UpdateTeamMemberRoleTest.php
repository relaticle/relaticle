<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\User;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

mutates(User::class);

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

test('only team owner can update team member roles', function () {
    $user = User::factory()->withTeam()->create();

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('managingRoleFor', $otherUser)
        ->set('currentRole', 'editor')
        ->call('updateRole')
        ->assertStatus(403);

    expect($otherUser->fresh()->hasTeamRole(
        $user->currentTeam->fresh(), 'admin'
    ))->toBeTrue();
});

test('viewer is a registered team role with only read ability', function (): void {
    $role = Jetstream::findRole(TeamRole::Viewer->value);

    expect($role)->not->toBeNull()
        ->and($role->key)->toBe('viewer')
        ->and($role->permissions)->toBe(['read']);
});

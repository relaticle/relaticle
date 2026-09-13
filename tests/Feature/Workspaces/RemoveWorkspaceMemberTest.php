<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\WorkspaceMemberRemovedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;

mutates(User::class);

test('workspace members can be removed from workspaces', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    expect($user->currentWorkspace->fresh()->users)->toBeEmpty();
});

test('removed workspace member receives notification', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    Notification::assertSentTo($otherUser, WorkspaceMemberRemovedNotification::class);
});

test('editor cannot remove workspace members', function () {
    $user = User::factory()->withWorkspace()->create();

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'editor']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember')
        ->assertStatus(403);
});

test('admin cannot remove the workspace owner', function () {
    Event::fake([TeamMemberRemoved::class]);

    $user = User::factory()->withWorkspace()->create();

    $user->currentWorkspace->users()->attach(
        $admin = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember');

    Event::assertNotDispatched(TeamMemberRemoved::class);
    expect($user->currentWorkspace->fresh()->owner->id)->toBe($user->id);
});

test('admin cannot remove a peer admin', function () {
    Event::fake([TeamMemberRemoved::class]);

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $adminA = User::factory()->create();
    $workspace->users()->attach($adminA, ['role' => 'admin']);

    $adminB = User::factory()->create();
    $workspace->users()->attach($adminB, ['role' => 'admin']);

    $this->actingAs($adminA);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $adminB->id)
        ->call('removeTeamMember');

    Event::assertNotDispatched(TeamMemberRemoved::class);
    expect($adminB->fresh()->hasWorkspaceRole($workspace->fresh(), 'admin'))->toBeTrue();
});

test('admin can still remove an editor', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => 'admin']);

    $editor = User::factory()->create();
    $workspace->users()->attach($editor, ['role' => 'editor']);

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $editor->id)
        ->call('removeTeamMember');

    expect($workspace->fresh()->users()->where('users.id', $editor->id)->exists())->toBeFalse();
});

test('admin can still leave the workspace themselves', function () {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->set('teamMemberIdBeingRemoved', $admin->id)
        ->call('removeTeamMember');

    expect($workspace->fresh()->users()->where('users.id', $admin->id)->exists())->toBeFalse();
});

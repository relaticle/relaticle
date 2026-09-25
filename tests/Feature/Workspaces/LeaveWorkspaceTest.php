<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;

mutates(User::class);

test('users can leave workspaces', function () {
    $user = User::factory()->withWorkspace()->create();

    $user->currentWorkspace->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->call('leaveTeam');

    expect($user->currentWorkspace->fresh()->users)->toBeEmpty();
});

test('workspace owners cant leave their own workspace', function () {
    $this->actingAs($user = User::factory()->withWorkspace()->create());

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentWorkspace])
        ->call('leaveTeam')
        ->assertHasErrors(['workspace']);

    expect($user->currentWorkspace->fresh())->not->toBeNull();
});

test('a stranger cannot leave a workspace they were never on, so no removal notice names it', function () {
    Notification::fake();

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $this->actingAs(User::factory()->withWorkspace()->create());

    // Authorization used to pass on the self-removal branch alone, which never
    // asked whether the caller was on this workspace. The detach was a no-op, but the
    // notification still went out carrying the workspace's name.
    Livewire::test(TeamMemberManager::class, ['team' => $workspace])
        ->call('leaveTeam');

    Notification::assertNothingSent();

    expect($workspace->fresh()->users)->toBeEmpty();
});

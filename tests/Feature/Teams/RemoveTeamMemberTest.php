<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\TeamMemberRemovedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;

mutates(User::class);

test('team members can be removed from teams', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    expect($user->currentTeam->fresh()->users)->toHaveCount(0);
});

test('removed team member receives notification', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('teamMemberIdBeingRemoved', $otherUser->id)
        ->call('removeTeamMember');

    Notification::assertSentTo($otherUser, TeamMemberRemovedNotification::class);
});

test('editor cannot remove team members', function () {
    $user = User::factory()->withTeam()->create();

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'editor']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember')
        ->assertStatus(403);
});

test('admin cannot remove the team owner', function () {
    Event::fake([TeamMemberRemoved::class]);

    $user = User::factory()->withTeam()->create();

    $user->currentTeam->users()->attach(
        $admin = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($admin);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->set('teamMemberIdBeingRemoved', $user->id)
        ->call('removeTeamMember');

    Event::assertNotDispatched(TeamMemberRemoved::class);
    expect($user->currentTeam->fresh()->owner->id)->toBe($user->id);
});

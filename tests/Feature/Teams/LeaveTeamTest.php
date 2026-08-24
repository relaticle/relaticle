<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;

mutates(User::class);

test('users can leave teams', function () {
    $user = User::factory()->withTeam()->create();

    $user->currentTeam->users()->attach(
        $otherUser = User::factory()->create(), ['role' => 'admin']
    );

    $this->actingAs($otherUser);

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->call('leaveTeam');

    expect($user->currentTeam->fresh()->users)->toHaveCount(0);
});

test('team owners cant leave their own team', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])
        ->call('leaveTeam')
        ->assertHasErrors(['team']);

    expect($user->currentTeam->fresh())->not->toBeNull();
});

test('a stranger cannot leave a team they were never on, so no removal notice names it', function () {
    Notification::fake();

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $this->actingAs(User::factory()->withTeam()->create());

    // Authorization used to pass on the self-removal branch alone, which never
    // asked whether the caller was on this team. The detach was a no-op, but the
    // notification still went out carrying the team's name.
    Livewire::test(TeamMemberManager::class, ['team' => $team])
        ->call('leaveTeam');

    Notification::assertNothingSent();

    expect($team->fresh()->users)->toHaveCount(0);
});

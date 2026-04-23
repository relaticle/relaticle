<?php

declare(strict_types=1);

use App\Livewire\App\Teams\DeleteTeam;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

mutates(DeleteTeam::class);

test('team owner can schedule team deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    $team = $user->currentTeam;

    Livewire::test(DeleteTeam::class, ['team' => $team])
        ->call('deleteTeam', $team);

    expect($team->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('team owner can cancel scheduled team deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    $team = $user->currentTeam;
    $team->update(['scheduled_deletion_at' => now()->addDays(30)]);

    Livewire::test(DeleteTeam::class, ['team' => $team])
        ->call('cancelTeamDeletion', $team);

    expect($team->refresh()->scheduled_deletion_at)->toBeNull();
});

test('personal teams cant be scheduled for deletion', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteTeam::class, ['team' => $user->personalTeam()])
        ->call('deleteTeam', $user->personalTeam())
        ->assertHasErrors(['team']);
});

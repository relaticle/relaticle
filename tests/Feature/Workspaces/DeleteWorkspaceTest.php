<?php

declare(strict_types=1);

use App\Livewire\App\Workspaces\DeleteWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

mutates(DeleteWorkspace::class);

test('workspace owner can schedule workspace deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;

    Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace])
        ->call('deleteWorkspace', $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('workspace owner can cancel scheduled workspace deletion', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    $workspace = $user->currentWorkspace;
    $workspace->update(['scheduled_deletion_at' => now()->addDays(30)]);

    Livewire::test(DeleteWorkspace::class, ['workspace' => $workspace])
        ->call('cancelWorkspaceDeletion', $workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
});

test('personal workspaces cant be scheduled for deletion', function () {
    $this->actingAs($user = User::factory()->withPersonalWorkspace()->create());

    Livewire::test(DeleteWorkspace::class, ['workspace' => $user->personalWorkspace()])
        ->call('deleteWorkspace', $user->personalWorkspace())
        ->assertHasErrors(['workspace']);
});

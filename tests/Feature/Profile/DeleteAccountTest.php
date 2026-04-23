<?php

declare(strict_types=1);

use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

mutates(DeleteAccount::class, User::class);

test('user can schedule account deletion with correct password', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount', 'password')
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();

    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('social user can schedule account deletion without password', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('user cannot schedule deletion with wrong password', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount', 'wrong-password')
        ->assertHasErrors(['password']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('user cannot schedule deletion when owning team with members', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    $team = $user->currentTeam;
    $team->users()->attach(User::factory()->create(), ['role' => 'editor']);

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount', 'password')
        ->assertHasErrors(['team']);
});

test('delete account component renders correctly', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->assertSuccessful()
        ->assertSee('Delete Account');
});

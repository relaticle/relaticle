<?php

declare(strict_types=1);

use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use App\Notifications\UserDeletionScheduledNotification;
use Filament\Actions\Testing\TestAction;
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
        ->assertHasNoErrors()
        ->assertNotified(__('profile.notifications.delete_account_blocked.title'));

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
    Notification::assertNothingSentTo($user);
});

test('delete account component renders correctly', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->assertSuccessful()
        ->assertSee('Delete Account')
        ->assertSee('Permanently delete your account after a 30-day grace period.')
        ->assertSee('Records in shared workspaces will remain without your profile.')
        ->assertDontSee('all your data');
});

test('password confirmation explains what deletion keeps and removes', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->mountAction(TestAction::make('deleteAccount')->schemaComponent())
        ->assertMountedActionModalSee('Your profile and sign-in account will be deleted after 30 days.')
        ->assertMountedActionModalSee('Shared workspace records will remain.')
        ->assertMountedActionModalSee('Enter your password to confirm.');
});

test('social account confirmation explains what deletion keeps and removes', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->mountAction(TestAction::make('deleteAccount')->schemaComponent())
        ->assertMountedActionModalSee('Your profile and sign-in account will be deleted after 30 days.')
        ->assertMountedActionModalSee('Shared workspace records will remain.')
        ->assertDontSee('Enter your password to confirm.');
});

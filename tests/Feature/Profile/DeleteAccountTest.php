<?php

declare(strict_types=1);

use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use Livewire\Livewire;

mutates(DeleteAccount::class, User::class);

test('social user can delete account without password', function () {
    $this->actingAs($user = User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertRedirect();

    expect($user->fresh())->toBeNull();
});

test('password user can delete account with correct password', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount', 'password')
        ->assertRedirect();

    expect($user->fresh())->toBeNull();
});

test('password user cannot delete account with wrong password', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount', 'wrong-password')
        ->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('password user cannot delete account without password', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('delete account component renders correctly', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->assertSuccessful()
        ->assertSee('Delete Account');
});

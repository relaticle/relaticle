<?php

declare(strict_types=1);

use App\Livewire\App\Teams\UpdateTeamName;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('team name and slug can be updated', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'New Name', 'slug' => 'new-name'])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasNoFormErrors();

    $team = $user->currentTeam->fresh();

    expect($team->name)->toBe('New Name')
        ->and($team->slug)->toBe('new-name');
});

test('slug is required', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Test Team', 'slug' => ''])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasFormErrors(['slug' => 'required']);
});

test('slug must be at least 3 characters', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Test Team', 'slug' => 'ab'])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasFormErrors(['slug']);
});

test('slug must match valid format', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Test Team', 'slug' => 'Invalid Slug!'])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasFormErrors(['slug']);
});

test('slug must be unique across teams', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Team::factory()->create(['slug' => 'taken-slug']);

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Test Team', 'slug' => 'taken-slug'])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasFormErrors(['slug']);
});

test('team can keep its own slug on update', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $currentSlug = $user->currentTeam->slug;

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Different Name', 'slug' => $currentSlug])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasNoFormErrors()
        ->assertNotified();
});

test('slug change triggers redirect', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'New Name', 'slug' => 'completely-new-slug'])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasNoFormErrors()
        ->assertRedirect();
});

test('same slug does not trigger redirect', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    $currentSlug = $user->currentTeam->slug;

    Livewire::test(UpdateTeamName::class, ['team' => $user->currentTeam])
        ->fillForm(['name' => 'Different Name', 'slug' => $currentSlug])
        ->call('updateTeamName', $user->currentTeam)
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertNoRedirect();
});

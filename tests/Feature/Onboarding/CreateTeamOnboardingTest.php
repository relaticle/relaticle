<?php

declare(strict_types=1);

use App\Filament\Pages\CreateTeam;
use App\Models\Team;
use App\Models\User;

mutates(CreateTeam::class);

it('renders the create team page for teamless users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('creates a team with name and auto-generated slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Acme Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Acme Corp')->first();

    expect($team)->not->toBeNull()
        ->and($team->slug)->toBe('acme-corp');
});

it('creates a team with a custom slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'my-workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Acme Corp')->first();

    expect($team)->not->toBeNull()
        ->and($team->slug)->toBe('my-workspace');
});

it('validates slug format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'INVALID SLUG!!',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('validates slug uniqueness', function (): void {
    $existingUser = User::factory()->create();
    Team::factory()->create(['slug' => 'taken-slug', 'user_id' => $existingUser->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'taken-slug',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('requires a team name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['name']);
});

it('marks first team as personal team', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'My First Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->ownedTeams->first();

    expect($team->personal_team)->toBeTrue();
});

it('marks subsequent teams as non-personal', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Second Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $secondTeam = $user->fresh()->ownedTeams()->where('name', 'Second Team')->first();

    expect($secondTeam->personal_team)->toBeFalse();
});

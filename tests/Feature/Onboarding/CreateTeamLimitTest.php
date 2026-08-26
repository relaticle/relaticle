<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use App\Policies\TeamPolicy;
use Filament\Actions\Testing\TestAction;
use Laravel\Pennant\Feature;

mutates(CreateTeam::class, TeamPolicy::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('allows a fourth workspace under the default cap', function (): void {
    $user = User::factory()->create();

    Team::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->get(route('filament.app.tenant.registration'))->assertSuccessful();
});

it('explains the workspace limit instead of returning a bare 404', function (): void {
    // A low cap keeps the fixture small; the point is the behavior at the cap,
    // whatever the configured number happens to be.
    config()->set('relaticle.workspaces.max_owned_per_user', 3);

    $user = User::factory()->create();

    Team::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    expect($user->refresh()->ownedTeams()->count())->toBe(3);

    $this->get(route('filament.app.tenant.registration'))
        ->assertRedirect()
        ->assertSessionHas('filament.notifications');
});

it('lets a user finish a wizard run whose workspace pushed them to the limit', function (): void {
    config()->set('relaticle.workspaces.max_owned_per_user', 3);

    $user = User::factory()->create();

    // Two existing workspaces: creating this one takes them to the cap of three.
    Team::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $component = livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Third Workspace',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ]);

    // Pre-creates the team, which takes the user to the cap.
    $component->callAction(TestAction::make('copyInviteLink')->schemaComponent());

    expect($user->refresh()->ownedTeams()->count())->toBe(3);

    // Without the in-flight exemption Filament would 404 this request, and Livewire
    // would swallow it: the form would simply stop responding.
    $component
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Team::query()->where('name', 'Third Workspace')->count())->toBe(1)
        ->and($user->refresh()->ownedTeams()->count())->toBe(3);
});

it('still refuses a brand new wizard once the user is at the limit', function (): void {
    config()->set('relaticle.workspaces.max_owned_per_user', 3);

    $user = User::factory()->create();

    Team::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    // A stale in-flight marker must not survive into a fresh visit.
    session()->put('onboarding.completing_workspace', 'stale');

    $this->get(route('filament.app.tenant.registration'))->assertRedirect();

    expect($user->refresh()->ownedTeams()->count())->toBe(3);
});

/**
 * The teams table already records that a workspace exists. What only the
 * client-side event carries is the referrer still on the session, so a channel
 * can be credited with an activated workspace and not just a signup.
 *
 * Flagged in afterRegister() so it fires once per finished wizard whichever
 * path created the row, and because getRedirectUrl() sends the user to the
 * dashboard next, the same event marks them as landed.
 */

<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Laravel\Pennant\Feature;

mutates(CreateTeam::class, CreateTeamAction::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('shows a step indicator and a back affordance in the wizard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/teams.create_team.actions.back'))
        ->assertSee('Step :current of :total');
});

it('relabels cancel once copying the invite link has created the workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Pre Created',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ]);

    expect($component->instance()->getCancelLabel())
        ->toBe(__('filament/pages/teams.create_team.actions.cancel'));

    $component->callAction(TestAction::make('copyInviteLink')->schemaComponent());

    $team = Team::query()->where('name', 'Pre Created')->firstOrFail();

    expect($component->instance()->getCancelLabel())
        ->toBe(__('filament/pages/teams.create_team.actions.go_to_workspace'))
        ->and($component->instance()->getCancelUrl())
        ->toBe(Dashboard::getUrl(['tenant' => $team]));
});

it('reconciles name and slug edited after the invite link pre-created the workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'First Name',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ]);

    $component->callAction(TestAction::make('copyInviteLink')->schemaComponent());

    $team = Team::query()->where('name', 'First Name')->firstOrFail();

    // The Back button makes this reachable: the user returns to step 1 and renames the
    // workspace after it has already been created.
    $component
        ->fillForm([
            'name' => 'Renamed Later',
            'slug' => 'renamed-later',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect($team->fresh())
        ->name->toBe('Renamed Later')
        ->slug->toBe('renamed-later')
        ->and(Team::query()->count())->toBe(1);
});

it('labels the submit button for what it will actually do', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateTeam::class)
        ->fillForm(['name' => 'Label Check', 'onboarding_use_case' => OnboardingUseCase::Other->value]);

    $component
        ->assertSee(__('filament/pages/teams.create_team.actions.get_started'))
        ->assertDontSee(__('filament/pages/teams.create_team.actions.send_invites'));

    $component->fillForm([
        'invites' => [['email' => 'someone@gmail.com', 'role' => 'editor']],
    ]);

    $component
        ->assertSee(__('filament/pages/teams.create_team.actions.send_invites'))
        ->assertDontSee(__('filament/pages/teams.create_team.actions.get_started'));
});

it('flags the workspace created event when the wizard finishes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Tracked Corp',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(session()->get('fathom.track_workspace_created'))->toBeTrue();
});

/**
 * Copying the invite link pre-creates the workspace so the URL can exist. A
 * user who stops there has not finished onboarding, and counting them would
 * inflate the very conversion this event measures.
 */
it('does not flag the workspace created event when the invite link only pre-creates the workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Abandoned Corp',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->callAction(TestAction::make('copyInviteLink')->schemaComponent());

    expect(Team::query()->where('name', 'Abandoned Corp')->exists())->toBeTrue()
        ->and(session()->has('fathom.track_workspace_created'))->toBeFalse();
});

/**
 * A second workspace is expansion, not acquisition. Its Fathom referrer is
 * whatever brought the user back that day, so crediting a channel with it
 * would be wrong, and counting it alongside first workspaces would push the
 * signup-to-workspace rate past 100%.
 */
it('does not flag the workspace created event for an additional workspace', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Second Corp',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Team::query()->where('name', 'Second Corp')->exists())->toBeTrue()
        ->and(session()->has('fathom.track_workspace_created'))->toBeFalse();
});

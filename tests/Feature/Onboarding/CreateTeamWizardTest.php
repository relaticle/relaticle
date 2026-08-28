<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Enums\OnboardingUseCase;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(CreateTeam::class, CreateTeamAction::class, StartProTrial::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('renders the create team page with wizard for teamless users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('resolves every wizard heading from translations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Every step's placeholders are in the DOM at once, so one render covers all four.
    // A mistyped key would surface here as the raw dotted key instead of the copy.
    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/teams.create_team.headings.workspace'))
        ->assertSee(__('filament/pages/teams.create_team.headings.attribution'))
        ->assertSee(__('filament/pages/teams.create_team.headings.attribution_description'))
        ->assertSee(__('filament/pages/teams.create_team.headings.use_case'))
        ->assertSee(__('filament/pages/teams.create_team.headings.use_case_description'))
        ->assertSee(__('filament/pages/teams.create_team.headings.use_case_hint'))
        ->assertSee(__('filament/pages/teams.create_team.headings.invite'))
        ->assertSee(__('filament/pages/teams.create_team.headings.invite_description'))
        ->assertSee(__('filament/pages/teams.create_team.headings.invite_subheading'))
        ->assertDontSee('filament/pages/teams.create_team.headings')
        ->assertDontSee('Workspace heading')
        ->assertDontSee('Attribution heading')
        ->assertDontSee('Use case heading')
        ->assertDontSee('Invite heading')
        ->assertDontSee('Invite subheading')
        ->assertDontSee('Onboarding referral source');
});

it('resolves every wizard form label from translations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/teams.create_team.form.workspace_name.label'))
        ->assertSee(__('filament/pages/teams.create_team.form.workspace_handle.label'))
        ->assertSee(__('filament/pages/teams.create_team.form.use_case_label'))
        ->assertSee(__('filament/pages/teams.create_team.form.invite_email_label'))
        ->assertSee(__('filament/pages/teams.create_team.form.invite_role_label'))
        ->assertDontSee('filament/pages/teams.create_team.form');
});

it('renders wizard for users who already have a team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('offers a way back to the current workspace for users who already have one', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    $component = livewire(CreateTeam::class);

    expect($component->instance()->getCancelUrl())
        ->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));

    $component->assertSee(__('filament/pages/teams.create_team.actions.cancel'));
});

it('offers no way back for teamless users, who have nowhere to go', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateTeam::class);

    expect($component->instance()->getCancelUrl())->toBeNull();

    $component->assertDontSee(__('filament/pages/teams.create_team.actions.cancel'));
});

it('creates a team with onboarding fields', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Acme Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Acme Corp')->first();

    expect($team)->not->toBeNull()
        ->and($team->slug)->toBe('acme-corp')
        ->and($team->onboarding_use_case)->toBe(OnboardingUseCase::Sales);
});

it('hides the account menu links while no workspace is bound, instead of sending them to the dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/app/new')
        ->assertSuccessful()
        ->assertDontSee(__('filament/panel.user_menu.settings'))
        ->assertDontSee(__('access-tokens.user_menu'));
});

it('shows the account menu links inside a workspace', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    $this->get('/app/'.$user->currentTeam->slug)
        ->assertSuccessful()
        ->assertSee(__('filament/panel.user_menu.settings'))
        ->assertSee(__('access-tokens.user_menu'));
});

it('clears the sub-options when the use case changes, so a switch can never strand the wizard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Switcher Co',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
        ])
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
        ])
        ->assertFormSet(['onboarding_context' => []])
        ->fillForm([
            'onboarding_context' => ['applications'],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Switcher Co')->first();

    expect($team)->not->toBeNull()
        ->and($team->onboarding_use_case)->toBe(OnboardingUseCase::Recruiting)
        ->and($team->onboarding_context)->toBe(['applications']);
});

it('automatically starts one 14-day Cloud Pro trial after hosted onboarding', function (): void {
    Feature::define(BillingFeature::class, true);

    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Trial Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    /** @var Team $team */
    $team = $user->refresh()->currentTeam;
    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->plan)->toBe(Plan::Pro)
        ->and($team->onGenericTrial())->toBeTrue()
        ->and($team->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($team->pro_trial_used_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('starts a fresh trial for each additional hosted workspace', function (): void {
    Feature::define(BillingFeature::class, true);

    $user = User::factory()->withPersonalTeam()->create();
    $user->currentTeam?->forceFill(['pro_trial_used_at' => now()])->save();
    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Second Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    /** @var Team $team */
    $team = $user->refresh()->currentTeam;

    expect($team->plan)->toBe(Plan::Pro)
        ->and($team->onGenericTrial())->toBeTrue()
        ->and($team->pro_trial_used_at)->not->toBeNull()
        ->and($team->hosted_free_grandfathered_at)->toBeNull();
});

it('creates a team with a custom slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
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
            'onboarding_use_case' => OnboardingUseCase::Other->value,
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
            'onboarding_use_case' => OnboardingUseCase::Other->value,
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
            'onboarding_use_case' => OnboardingUseCase::Other->value,
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
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
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
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Second Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $secondTeam = $user->fresh()->ownedTeams()->where('name', 'Second Team')->first();

    expect($secondTeam->personal_team)->toBeFalse();
});

it('redirects first team to dashboard with notification', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Redirect Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertNotified('Workspace created')
        ->assertRedirect(Dashboard::getUrl(['tenant' => $user->fresh()->currentTeam]));
});

it('redirects subsequent teams to dashboard', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Second Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl(['tenant' => $user->fresh()->currentTeam]));
});

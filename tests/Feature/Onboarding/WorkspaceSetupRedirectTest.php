<?php

declare(strict_types=1);

use App\Enums\OnboardingStep;
use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupWorkspace;
use App\Filament\Resources\PeopleResource;
use App\Http\Middleware\RedirectToWorkspaceSetup;
use App\Models\User;
use Laravel\Pennant\Feature;

mutates(RedirectToWorkspaceSetup::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);

    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->addDays(14),
        'onboarding_step' => OnboardingStep::UseCase,
    ])->save();

    $this->setupUrl = SetupWorkspace::getUrl(['tenant' => $this->workspace]);
});

it('sends the owner of an unfinished workspace to setup from the dashboard', function (): void {
    $this->actingAs($this->owner)
        ->get(Dashboard::getUrl(['tenant' => $this->workspace]))
        ->assertRedirect($this->setupUrl);
});

it('sends the owner of an unfinished workspace to setup from a resource page', function (): void {
    $this->actingAs($this->owner)
        ->get(PeopleResource::getUrl('index', ['tenant' => $this->workspace]))
        ->assertRedirect($this->setupUrl);
});

it('lets the owner open the setup page itself', function (): void {
    $this->actingAs($this->owner)
        ->get($this->setupUrl)
        ->assertOk();
});

it('lets a member into a workspace whose owner is still in setup', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);

    $this->actingAs($member)
        ->get(Dashboard::getUrl(['tenant' => $this->workspace]))
        ->assertOk();
});

it('leaves a finished workspace alone', function (): void {
    $this->workspace->forceFill(['onboarding_step' => null])->save();

    $this->actingAs($this->owner)
        ->get(Dashboard::getUrl(['tenant' => $this->workspace]))
        ->assertOk();
});

it('does not redirect a request that expects JSON', function (): void {
    $this->actingAs($this->owner)
        ->getJson(Dashboard::getUrl(['tenant' => $this->workspace]))
        ->assertOk();
});

it('leaves a paused workspace in setup to billing instead of looping', function (): void {
    $this->workspace->forceFill([
        'plan' => Plan::Free,
        'trial_ends_at' => null,
        'pro_trial_used_at' => now()->subDays(20),
    ])->save();

    $billingUrl = route('filament.app.pages.billing', ['tenant' => $this->workspace->slug]);

    $this->actingAs($this->owner);

    $this->get(Dashboard::getUrl(['tenant' => $this->workspace]))->assertRedirect($billingUrl);
    $this->get($billingUrl)->assertOk();
    $this->get($this->setupUrl)->assertRedirect($billingUrl);
});

it('sends the owner of an unfinished workspace to setup from billing while the workspace has access', function (): void {
    $this->actingAs($this->owner)
        ->get(route('filament.app.pages.billing', ['tenant' => $this->workspace->slug]))
        ->assertRedirect($this->setupUrl);
});

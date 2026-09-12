<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use App\Filament\Pages\CreateWorkspace;
use App\Models\User;

mutates(CreateWorkspace::class);

it('new user without workspaces is directed to onboarding wizard', function (): void {
    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->assertSee('Your name')
        // Step 1: Create workspace
        ->type('[id="form.name"]', 'My First Workspace')
        ->type('[id="form.slug"]', 'my-first-workspace')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        // Step 2: Attribution (optional, just proceed)
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        // Step 3: Use case (select "Other" which has no sub-options)
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Get started')
        ->assertPathContains('/my-first-workspace');

    $user->refresh();

    expect($user->ownedWorkspaces)->toHaveCount(1)
        ->and($user->ownedWorkspaces->first()->name)->toBe('My First Workspace');
});

it('stores the use case and its sub-option chosen in the browser', function (): void {
    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Hiring Desk')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-recruiting"]')
        ->waitForText('Pick what applies to you.')
        ->click('[for$="onboarding_context-sourcing"]')
        ->press('Get started')
        ->assertPathContains('/hiring-desk');

    $user->refresh();

    $workspace = $user->ownedWorkspaces->first();

    expect($workspace->onboarding_use_case)->toBe(OnboardingUseCase::Recruiting)
        ->and($workspace->onboarding_context)->toBe(['sourcing'])
        ->and($workspace->name)->toBe('Hiring Desk');
});

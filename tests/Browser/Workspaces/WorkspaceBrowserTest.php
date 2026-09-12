<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;

mutates(CreateWorkspace::class);

it('can create a new workspace through the browser', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->assertDontSee('Your name')
        // Step 1: Workspace
        ->type('[id="form.name"]', 'Second Workspace')
        ->type('[id="form.slug"]', 'second-workspace')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        // Step 2: Attribution (skip)
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        // Step 3: Use case
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Get started')
        ->assertPathIs('/app/second-workspace');

    expect(Workspace::where('name', 'Second Workspace')->where('user_id', $user->id)->exists())->toBeTrue();
});

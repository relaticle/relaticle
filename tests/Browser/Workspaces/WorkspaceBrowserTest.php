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
        ->type('[id="form.name"]', 'Second Workspace')
        ->type('[id="form.slug"]', 'second-workspace')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertPathIs('/app/second-workspace/setup')
        ->assertDontSee('How did you hear about us?')
        ->waitForEvent('load')
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Get started')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathIs('/app/second-workspace')
        ->assertSee('Workspace created');

    expect(Workspace::where('name', 'Second Workspace')->where('user_id', $user->id)->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Filament\Pages\CreateTeam;
use App\Models\User;

mutates(CreateTeam::class);

it('new user without teams is directed to onboarding wizard', function (): void {
    $user = User::factory()->create();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
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
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        // Step 4: Invite (skip, just submit)
        ->press('Send invites')
        ->assertPathContains('/my-first-workspace');

    $user->refresh();

    expect($user->ownedTeams)->toHaveCount(1)
        ->and($user->ownedTeams->first()->name)->toBe('My First Workspace');
});

it('completes the wizard when Copy invite link is clicked before Send invites', function (): void {
    $user = User::factory()->create();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Copy Link First')
        ->type('[id="form.slug"]', 'copy-link-first')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        ->press('Copy invite link')
        ->waitForText('Invite link copied')
        ->press('Send invites')
        ->assertPathContains('/copy-link-first');

    $user->refresh();

    expect($user->ownedTeams)->toHaveCount(1)
        ->and($user->ownedTeams->first()->slug)->toBe('copy-link-first');
});

it(
    'persists slug edits made after Copy invite link was clicked',
)->todo('Scenario unreachable in UI: the wizard uses ->hiddenHeader() in CreateTeam::form() and the custom onboarding wizard view does not expose a previous-step action, so a user cannot return to step 1 after clicking Copy invite link. The reconcile branch in CreateTeam::handleRegistration is exercised (in its no-op, same-slug shape) by "completes the wizard when Copy invite link is clicked before Send invites" above; the $team->update($updates) call itself is not reachable from any test since no UI path allows slug/name edits post-Copy, and direct mutation of $this->tenant in Livewire tests does not persist across ->call(). Kept as defense-in-depth for future UI changes that may relax the hidden-header constraint.');

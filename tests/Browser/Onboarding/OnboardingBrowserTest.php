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
        // Step 4: Invite. With no address entered the submit button reads
        // "Get started" rather than "Send invites".
        ->press('Get started')
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
        ->press('Get started')
        ->assertPathContains('/copy-link-first');

    $user->refresh();

    expect($user->ownedTeams)->toHaveCount(1)
        ->and($user->ownedTeams->first()->slug)->toBe('copy-link-first');
});

it('persists slug edits made after Copy invite link was clicked', function (): void {
    $user = User::factory()->create();

    // Back navigation makes this reachable: the workspace already exists from Copy
    // invite link, and the user returns to step 1 to rename it. Without the reconcile
    // in CreateTeam::handleRegistration the edit would be silently discarded.
    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Renamed After Copy')
        ->type('[id="form.slug"]', 'before-the-edit')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        ->press('Copy invite link')
        ->waitForText('Invite link copied')
        ->press('Back')
        ->waitForText('Help us customize your workspace')
        ->press('Back')
        ->waitForText('How did you hear about us?')
        ->press('Back')
        ->waitForText('Create your workspace')
        ->type('[id="form.slug"]', 'after-the-edit')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        ->press('Get started')
        ->assertPathContains('/after-the-edit');

    $user->refresh();

    expect($user->ownedTeams)->toHaveCount(1)
        ->and($user->ownedTeams->first()->slug)->toBe('after-the-edit');
});

it('offers the invite skip only while there is an invite to skip', function (): void {
    $user = User::factory()->create();

    // With the fields empty, "Skip for now" would submit exactly what the primary
    // button submits, so it stays hidden until an address is entered.
    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Conditional Skip')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        ->assertSee('Get started')
        ->assertDontSee('Skip for now')
        ->type('input[type=email] >> nth=0', 'teammate@gmail.com')
        // The field syncs on blur, so move focus before asserting.
        ->click('input[type=email] >> nth=1')
        ->waitForText('Send invites')
        ->assertSee('Skip for now');
});

it('keeps the leave-wizard link off the invite step for a first-run user', function (): void {
    $user = User::factory()->create();

    // A user who owns nothing has no cancel link on first paint, so the block that
    // tracks the wizard step only mounts once "Copy invite link" creates a workspace.
    // It still has to know the wizard is on the last step, or the footer ends up with
    // two competing calls to action.
    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->assertDontSee('Go to workspace')
        ->type('[id="form.name"]', 'Late Mounted Cancel')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Continue')
        ->waitForText('Collaborate with your team')
        ->press('Copy invite link')
        ->waitForText('Invite link copied')
        ->assertDontSee('Go to workspace')
        // Back on step one it is the way out again, so it has to reappear.
        ->press('Back')
        ->waitForText('Help us customize your workspace')
        ->press('Back')
        ->waitForText('How did you hear about us?')
        ->press('Back')
        ->waitForText('Create your workspace')
        ->assertSee('Go to workspace');
});

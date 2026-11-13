<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;

mutates(CreateWorkspace::class);

it('can create a new workspace through the browser', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, '/app/new')
        ->assertSee('Create your workspace')
        ->assertDontSee('Your name')
        ->type('[id="form.name"]', 'Second Workspace')
        ->type('[id="form.slug"]', 'second-workspace')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertPathIs('/app/second-workspace/setup')
        ->assertDontSee('How did you hear about us?')
        ->assertScript('new Promise(resolve => { const ready = () => document.querySelector(\'[x-data^="filamentActionModals"]\')?._x_dataStack !== undefined; const poll = () => ready() ? resolve(true) : setTimeout(poll, 25); poll(); })', true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->assertScript(asyncAlpineComponentsLoaded())
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathIs('/app/second-workspace')
        ->assertSee('Workspace created');

    expect(Workspace::where('name', 'Second Workspace')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('fits every role column of the compare roles table on a phone', function (int $width): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, "/app/{$workspace->slug}/workspace/members", $width, 700)
        ->press('Invite workspace members')
        ->press('Compare roles')
        ->assertPresent('[data-role-matrix]')
        ->assertScript("(() => { const scroller = document.querySelector('[data-role-matrix]').parentElement; return scroller.scrollWidth <= scroller.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
})->with([390, 320]);

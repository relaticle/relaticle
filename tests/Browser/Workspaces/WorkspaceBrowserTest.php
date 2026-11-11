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
        ->waitForText('Help us customize your workspace')
        ->assertPathIs('/app/second-workspace/setup')
        ->assertDontSee('How did you hear about us?')
        ->assertScript(livewireStarted())
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

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 700)
        ->navigate("/app/{$workspace->slug}/workspace/members")
        ->press('Invite workspace members')
        ->press('Compare roles')
        ->assertPresent('[data-role-matrix]')
        ->assertScript("(() => { const scroller = document.querySelector('[data-role-matrix]').parentElement; return scroller.scrollWidth <= scroller.clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
})->with([390, 320]);

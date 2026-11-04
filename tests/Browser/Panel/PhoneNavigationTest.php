<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Providers\AppServiceProvider;

mutates(AppServiceProvider::class);

const OPEN_SHEET = "[...document.querySelectorAll('.fi-dropdown-panel[x-ref=panel]')].find((panel) => panel.style.display === 'block')";

const ROW_MENU_TRIGGER = '.fi-ta-row:first-of-type .fi-ta-actions .fi-dropdown-trigger';

function phoneCompaniesPage(User $user): mixed
{
    $workspace = $user->ownedWorkspaces()->first();

    Company::factory()->count(2)->recycle([$user, $workspace])->create();

    return loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/companies");
}

it('shows a tab bar on phones whose More button opens the sidebar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->assertVisible('.fi-bottom-nav')
        ->assertScript("document.querySelector('.fi-bottom-nav [aria-current=page]').getAttribute('href').endsWith('/companies')", true)
        ->click('.fi-bottom-nav button')
        ->assertScript("Alpine.store('sidebar').isOpen", true)
        ->assertNoJavaScriptErrors();
});

it('keeps the tab bar off desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->assertScript("getComputedStyle(document.querySelector('.fi-bottom-nav')).display", 'none')
        ->assertNoJavaScriptErrors();
});

it('opens a row menu as a sheet along the bottom edge of a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->click(ROW_MENU_TRIGGER)
        ->wait(0.5)
        ->assertScript('(() => { const r = '.OPEN_SHEET.'.getBoundingClientRect(); return r.left === 0 && r.right === innerWidth && Math.round(r.bottom) === innerHeight; })()', true)
        ->assertNoJavaScriptErrors();
});

it('closes the sheet on a tap outside it without opening the record underneath', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    phoneCompaniesPage($user)
        ->click(ROW_MENU_TRIGGER)
        ->assertScript(OPEN_SHEET.' !== undefined', true)
        ->click('.fi-ta-row:nth-of-type(2) a.fi-ta-col >> nth=0')
        ->wait(0.5)
        ->assertScript(OPEN_SHEET.' === undefined', true)
        ->assertPathIs("/app/{$workspace->slug}/companies")
        ->assertNoJavaScriptErrors();
});

it('docks a confirmation dialog to the bottom edge of a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->click(ROW_MENU_TRIGGER)
        ->click(".fi-dropdown-panel[style*='display: block'] .fi-dropdown-list-item:last-child")
        ->assertVisible('.fi-modal-open .fi-modal-window')
        ->wait(0.5)
        ->assertScript("Math.round(document.querySelector('.fi-modal-open .fi-modal-window').getBoundingClientRect().bottom) === innerHeight", true)
        ->assertNoJavaScriptErrors();
});

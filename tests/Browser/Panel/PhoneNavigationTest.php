<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Providers\AppServiceProvider;

mutates(AppServiceProvider::class);

const OPEN_SHEET = "[...document.querySelectorAll('.fi-dropdown-panel[x-ref=panel]')].find((panel) => panel.style.display === 'block')";

const ROW_MENU_TRIGGER = '.fi-ta-row:first-of-type .fi-ta-actions .fi-dropdown-trigger';

const SHEET_HOLDS_BOTTOM_EDGE = '(() => '.OPEN_SHEET.'.contains(document.elementFromPoint(innerWidth / 2, innerHeight - 4)))()';

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
        ->assertScript(SHEET_HOLDS_BOTTOM_EDGE, true)
        ->assertScript('getComputedStyle(document.documentElement).overflowY', 'hidden')
        ->assertNoJavaScriptErrors();
});

it('keeps a sheet opened on a record page above the tab bar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $person = People::factory()->recycle([$user, $workspace])->create();
    Task::factory()->recycle([$user, $workspace])->create()->people()->attach($person);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->click('.fi-record-pane .fi-ta-row .fi-ta-actions .fi-dropdown-trigger')
        ->wait(0.5)
        ->assertScript(SHEET_HOLDS_BOTTOM_EDGE, true)
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

it('centres a notification across a phone screen', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    $page = phoneCompaniesPage($user)->resize(440, 956);

    $page->script("new FilamentNotification().title('Saved').success().send()");

    $page->wait(0.5)
        ->assertScript('(() => { const toast = document.querySelector(".fi-no-notification").getBoundingClientRect(); return Math.round(toast.left) === Math.round(innerWidth - toast.right); })()', true)
        ->assertNoJavaScriptErrors();
});

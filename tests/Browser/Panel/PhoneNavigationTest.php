<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Providers\AppServiceProvider;

mutates(AppServiceProvider::class);

const OPEN_SHEET = "[...document.querySelectorAll('.fi-dropdown-panel[x-ref=panel]')].find((panel) => panel.style.display === 'block')";

const BULK_MORE_TRIGGER = '.fi-bulk-bar .fi-dropdown-trigger';

const FIRST_ROW_CHECKBOX = '.fi-ta-row:first-of-type .fi-ta-record-checkbox';

const MODAL_SPANS_SCREEN = "(() => { const r = document.querySelector('.fi-modal-open .fi-modal-window').getBoundingClientRect(); return r.left === 0 && Math.round(r.right) === innerWidth; })()";

const SHEET_HOLDS_BOTTOM_EDGE = '(() => '.OPEN_SHEET.'.contains(document.elementFromPoint(innerWidth / 2, innerHeight - 4)))()';

function phoneCompaniesPage(User $user): mixed
{
    $workspace = $user->ownedWorkspaces()->first();

    Company::factory()->count(2)->recycle([$user, $workspace])->create();

    return visitAs($user, "/app/{$workspace->slug}/companies", 393, 852);
}

it('puts Home, People, Companies and Opportunities in the tab bar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    $labels = phoneCompaniesPage($user)
        ->script("[...document.querySelectorAll('.fi-bottom-nav a span')].map((label) => label.textContent.trim())");

    expect($labels)->toBe([
        Dashboard::getNavigationLabel(),
        PeopleResource::getNavigationLabel(),
        CompanyResource::getNavigationLabel(),
        OpportunityResource::getNavigationLabel(),
    ]);
});

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

it('closes the drawer on Escape', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->click('.fi-bottom-nav button')
        ->assertScript("Alpine.store('sidebar').isOpen", true)
        ->keys('#fi-main-sidebar nav a >> nth=0', 'Escape')
        ->assertScript("Alpine.store('sidebar').isOpen", false)
        ->assertNoJavaScriptErrors();
});

it('closes the drawer from a 44px close button in its header', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->click('.fi-bottom-nav button')
        ->assertScript("Alpine.store('sidebar').isOpen", true)
        ->assertScript("(() => { const box = document.querySelector('.fi-sidebar-drawer-close-btn').getBoundingClientRect(); return Math.round(box.width) >= 44 && Math.round(box.height) >= 44 && document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2).closest('.fi-sidebar-drawer-close-btn') !== null; })()", true)
        ->click('.fi-sidebar-drawer-close-btn')
        ->assertScript("Alpine.store('sidebar').isOpen", false)
        ->assertNoJavaScriptErrors();
});

it('shows the drawer close button wherever the sidebar is a drawer and hides it on desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $closeButtonDisplay = "getComputedStyle(document.querySelector('.fi-sidebar-drawer-close-btn')).display";

    $page = phoneCompaniesPage($user)->resize(768, 1024);
    $page->script("Alpine.store('sidebar').open()");

    expect($page->script($closeButtonDisplay))->not->toBe('none');

    $page->resize(1440, 900);

    expect($page->script($closeButtonDisplay))->toBe('none');
});

it('keeps a tab active on the pages beneath it and not More', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    phoneCompaniesPage($user)
        ->navigate("/app/{$workspace->slug}/companies/import")
        ->assertScript("document.querySelector('.fi-bottom-nav [aria-current=page]').getAttribute('href').endsWith('/companies')", true)
        ->assertScript("document.querySelectorAll('.fi-bottom-nav .fi-active').length", 1)
        ->assertScript("document.querySelector('.fi-bottom-nav button').classList.contains('fi-active')", false)
        ->navigate("/app/{$workspace->slug}/tasks")
        ->assertScript("document.querySelector('.fi-bottom-nav button').classList.contains('fi-active')", true)
        ->assertScript("document.querySelectorAll('.fi-bottom-nav [aria-current=page]').length", 0)
        ->assertNoJavaScriptErrors();
});

it('keeps the drawer search reachable with the getting started checklist on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    $workspace = $user->ownedWorkspaces()->first();
    $page = phoneCompaniesPage($user);
    $page->script("localStorage.setItem('activation-checklist:open', 'true')");

    $page->navigate("/app/{$workspace->slug}/companies")
        ->click('.fi-bottom-nav button')
        ->assertScript("(() => { const search = document.querySelector('.fi-sidebar-search input').getBoundingClientRect(); return document.elementFromPoint(search.x + search.width / 2, search.y + search.height / 2).closest('.fi-sidebar-search') !== null; })()", true)
        ->assertNoJavaScriptErrors();
});

it('keeps the tab bar off desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, "/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->assertScript("getComputedStyle(document.querySelector('.fi-bottom-nav')).display", 'none')
        ->assertNoJavaScriptErrors();
});

it('opens the bulk bar\'s More menu as a sheet along the bottom edge of a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->click(FIRST_ROW_CHECKBOX)
        ->click(BULK_MORE_TRIGGER)
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

    visitAs($user, "/app/{$workspace->slug}/people/{$person->getKey()}", 393, 852)
        ->click('.fi-record-pane .fi-tabs-item:nth-child(3)')
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
        ->click(FIRST_ROW_CHECKBOX)
        ->click(BULK_MORE_TRIGGER)
        ->assertScript(OPEN_SHEET.' !== undefined', true)
        ->click('.fi-ta-row:nth-of-type(2) a.fi-ta-col >> nth=0')
        ->wait(0.5)
        ->assertScript(OPEN_SHEET.' === undefined', true)
        ->assertPathIs("/app/{$workspace->slug}/companies")
        ->assertNoJavaScriptErrors();
});

it('docks a confirmation dialog to the bottom edge of a phone, edge to edge on a wide one', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();

    phoneCompaniesPage($user)
        ->resize(480, 852)
        ->click(FIRST_ROW_CHECKBOX)
        ->click(BULK_MORE_TRIGGER)
        ->click(".fi-dropdown-panel[style*='display: block'] .fi-dropdown-list-item:last-child")
        ->assertVisible('.fi-modal-open .fi-modal-window')
        ->wait(0.5)
        ->assertScript("Math.round(document.querySelector('.fi-modal-open .fi-modal-window').getBoundingClientRect().bottom) === innerHeight", true)
        ->assertScript(MODAL_SPANS_SCREEN, true)
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

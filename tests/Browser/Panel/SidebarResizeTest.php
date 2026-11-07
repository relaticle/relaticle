<?php

declare(strict_types=1);

use App\Models\User;

it('resizes the sidebar from its edge, keeps the width across pages, and resets on double click', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $width = '(() => Math.round(document.querySelector(".fi-sidebar").getBoundingClientRect().width))()';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->assertScript($width, 288)
        ->keys('.fi-sidebar-resize-handle', ['ArrowRight', 'ArrowRight'])
        ->assertScript($width, 320)
        ->assertScript('localStorage.getItem("sidebar-width")', '320')
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertScript($width, 320)
        ->drag('.fi-sidebar-resize-handle', '.fi-topbar .fi-user-menu-trigger')
        ->assertScript($width, 360);

    $page->script('document.querySelector(".fi-sidebar-resize-handle").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }))');

    $page->assertScript($width, 288)
        ->assertScript('localStorage.getItem("sidebar-width")', null)
        ->assertNoJavaScriptErrors();
});

it('collapses the sidebar when dragged past half its minimum and reopens it from the rail', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $width = '(() => Math.round(document.querySelector(".fi-sidebar").getBoundingClientRect().width))()';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->drag('.fi-sidebar-resize-handle', '.fi-sidebar-nav .fi-sidebar-item-icon >> nth=0')
        ->assertScript($width, 48)
        ->assertScript('Alpine.store("sidebar").isOpen', false)
        ->assertScript('localStorage.getItem("sidebar-width")', null);

    $page->refresh()
        ->assertScript($width, 48)
        ->drag('.fi-sidebar-resize-handle', '.fi-topbar .fi-user-menu-trigger')
        ->assertScript($width, 360)
        ->assertScript('Alpine.store("sidebar").isOpen', true)
        ->assertNoJavaScriptErrors();
});

it('collapses the sidebar with an arrow key past its minimum and reopens it from the rail', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $width = '(() => Math.round(document.querySelector(".fi-sidebar").getBoundingClientRect().width))()';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->keys('.fi-sidebar-resize-handle', ['Home'])
        ->assertScript($width, 220)
        ->keys('.fi-sidebar-resize-handle', ['ArrowLeft'])
        ->assertScript('Alpine.store("sidebar").isOpen', false)
        ->assertScript($width, 48)
        ->keys('.fi-sidebar-resize-handle', ['ArrowRight'])
        ->assertScript('Alpine.store("sidebar").isOpen', true)
        ->assertScript($width, 220)
        ->assertNoJavaScriptErrors();
});

it('fits every get started step on one line in the default sidebar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900);
    $page->script('localStorage.setItem("activation-checklist:open", "true")');

    $page->refresh()
        ->assertScript('document.querySelectorAll("[data-testid=activation-step]").length > 0', true)
        ->assertScript('[...document.querySelectorAll("[data-testid=activation-step] .text-sm")].filter((label) => label.getBoundingClientRect().height > parseFloat(getComputedStyle(label).lineHeight) + 1).map((label) => label.innerText.trim())', [])
        ->assertNoJavaScriptErrors();
});

it('focuses the sidebar search field from a click on its key cap or magnifier', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $searchIsFocused = 'document.activeElement.matches(".fi-sidebar-search .fi-input")';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->click('.fi-sidebar-search .fi-input-wrp-label')
        ->assertScript($searchIsFocused, true);

    $page->script('document.activeElement.blur()');

    $page->assertScript($searchIsFocused, false)
        ->click('.fi-sidebar-search .fi-input-wrp-prefix')
        ->assertScript($searchIsFocused, true)
        ->assertNoJavaScriptErrors();
});

it('keeps every navigation row 32px tall on the collapsed rail', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $rowHeights = '[...document.querySelectorAll(".fi-sidebar-nav .fi-sidebar-item-btn")].filter((row) => row.getClientRects().length > 0).every((row) => Math.round(row.getBoundingClientRect().height) === 32)';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->assertScript($rowHeights, true)
        ->click('[data-sidebar-workspace-toggle]')
        ->wait(0.5)
        ->assertScript('Alpine.store("sidebar").isOpen', false)
        ->assertScript($rowHeights, true)
        ->assertNoJavaScriptErrors();
});

it('hands the toggle to the top bar on collapse and back on expand, keeping the navigation in place', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $navTop = 'Math.round(document.querySelector(".fi-sidebar-nav .fi-sidebar-item-icon").getBoundingClientRect().top)';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->assertVisible('[data-sidebar-workspace-toggle]')
        ->assertMissing('[data-sidebar-expand-toggle]');

    $openNavTop = $page->script($navTop);

    $page->click('[data-sidebar-workspace-toggle]')
        ->wait(0.5)
        ->assertScript('Alpine.store("sidebar").isOpen', false)
        ->assertScript('getComputedStyle(document.querySelector("[data-sidebar-workspace-toggle]")).visibility', 'hidden')
        ->assertVisible('nav[aria-label=Topbar] [data-sidebar-expand-toggle]')
        ->assertScript($navTop, $openNavTop)
        ->click('[data-sidebar-expand-toggle]')
        ->wait(0.5)
        ->assertScript('Alpine.store("sidebar").isOpen', true)
        ->assertMissing('[data-sidebar-expand-toggle]')
        ->assertVisible('[data-sidebar-workspace-toggle]')
        ->assertNoJavaScriptErrors();
});

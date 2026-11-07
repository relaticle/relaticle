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

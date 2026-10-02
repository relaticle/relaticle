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
        ->assertScript($width, 256)
        ->keys('.fi-sidebar-resize-handle', ['ArrowRight', 'ArrowRight'])
        ->assertScript($width, 288)
        ->assertScript('localStorage.getItem("sidebar-width")', '288')
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertScript($width, 288)
        ->drag('.fi-sidebar-resize-handle', '.fi-topbar .fi-user-menu-trigger')
        ->assertScript($width, 360);

    $page->script('document.querySelector(".fi-sidebar-resize-handle").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }))');

    $page->assertScript($width, 256)
        ->assertScript('localStorage.getItem("sidebar-width")', null)
        ->assertNoJavaScriptErrors();
});

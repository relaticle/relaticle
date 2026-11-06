<?php

declare(strict_types=1);

use App\Models\User;

const THEME_COLOR = '[...document.querySelectorAll("meta[name=theme-color]")].filter((meta) => !meta.media || matchMedia(meta.media).matches).map((meta) => meta.content).join()';

it('colours the browser bar with the theme picked in the panel, not the system one', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)->assertPathIs("/app/{$workspace->slug}");

    $page->script('localStorage.setItem("filament:theme:mode", "dark")');

    $page->navigate("/app/{$workspace->slug}")
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript(THEME_COLOR, '#18181b');

    $page->script('document.documentElement.classList.remove("dark")');

    $page->assertScript(THEME_COLOR, '#ffffff')
        ->assertNoJavaScriptErrors();
});

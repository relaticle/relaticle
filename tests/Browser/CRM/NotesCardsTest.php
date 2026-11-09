<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;

it('keeps a note card\'s actions showing while its menu is open and the pointer has left', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Note::factory()->recycle([$user, $workspace])->create();

    $actions = '.fi-ta-content-grid .fi-ta-record .fi-ta-actions';
    $actionsOpacity = "getComputedStyle(document.querySelector('{$actions}')).opacity";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes")
        ->hover('.fi-ta-content-grid .fi-ta-record')
        ->click("{$actions} .fi-dropdown-trigger > button");

    $page->script('document.activeElement.blur()');

    $page->hover('.fi-sidebar-nav .fi-sidebar-item-btn >> nth=0')
        ->wait(0.3)
        ->assertScript("{$actionsOpacity} === '1'", true)
        ->assertNoJavaScriptErrors();
});

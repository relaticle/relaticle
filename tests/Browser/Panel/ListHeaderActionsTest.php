<?php

declare(strict_types=1);

use App\Models\User;

it('keeps the list Import / Export menu inside the viewport', function (int $width, int $height): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $panelFits = '(() => { const r = [...document.querySelectorAll(".fi-header .fi-dropdown-panel")].find((p) => p.offsetParent !== null).getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, $height)
        ->navigate("/app/{$workspace->slug}/opportunities")
        ->click('.fi-header button.fi-ac-btn-group:visible')
        ->assertSee(__('filament/resources/opportunity.pages.list.actions.import.label'))
        ->assertScript($panelFits, true)
        ->assertNoJavaScriptErrors();
})->with([
    'phone' => [393, 852],
    'desktop' => [1440, 900],
]);

<?php

declare(strict_types=1);

use App\Models\User;

it('keeps the list Import / Export menu inside the viewport', function (int $width, int $height): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $panelFits = '(() => { const r = [...document.querySelectorAll(".fi-header .fi-dropdown-panel")].find((p) => p.getClientRects().length > 0).getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()';

    visitAs($user, "/app/{$workspace->slug}/opportunities?view=all", $width, $height)
        ->click('.fi-header button.fi-ac-btn-group:visible')
        ->assertSee(__('filament/resources/opportunity.pages.list.actions.import.label'))
        ->assertScript($panelFits, true)
        ->assertNoJavaScriptErrors();
})->with([
    'phone' => [393, 852],
    'desktop' => [1440, 900],
]);

it('keeps the list header actions inside the page padding on a phone', function (string $page): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $actionsFit = '[...document.querySelectorAll(".fi-header-actions-ctn > *")].every((action) => action.getBoundingClientRect().right <= window.innerWidth - 16)';

    visitAs($user, "/app/{$workspace->slug}/{$page}", 393, 852)
        ->assertScript($actionsFit, true)
        ->assertNoJavaScriptErrors();
})->with(['opportunities', 'notes?view=cards']);

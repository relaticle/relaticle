<?php

declare(strict_types=1);

use App\Filament\Pages\Workspace\CustomFields;
use App\Models\User;

mutates(CustomFields::class);

it('stacks the entity tabs above the fields and keeps the name column readable on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $tabsAboveFields = '(() => { const tabs = [...document.querySelectorAll(".fi-page-content .fi-tabs")].find((tabs) => tabs.getClientRects().length).getBoundingClientRect(); const table = document.querySelector(".fi-page-content .fi-ta").getBoundingClientRect(); return tabs.bottom <= table.top; })()';
    $nameColumnReadable = '(() => { const header = [...document.querySelectorAll(".fi-page-content .fi-ta *")].find((element) => getComputedStyle(element).display === "grid"); return header.children[1].getBoundingClientRect().width >= 150; })()';

    visitAs($user, "/app/{$workspace->slug}/workspace/custom-fields", 390, 844)
        ->assertScript($tabsAboveFields, true)
        ->assertScript($nameColumnReadable, true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

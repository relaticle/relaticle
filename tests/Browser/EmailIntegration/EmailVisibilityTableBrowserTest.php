<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Livewire\EmailVisibilityTable;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

mutates(EmailVisibilityTable::class);

it('stacks each visibility entry into a card with a full-width search on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    WorkspaceEmailBlocklist::factory()->protected()->domain('acme.example')->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/workspace/email/privacy")
        ->waitForText('acme.example')
        ->assertScript('document.documentElement.scrollWidth <= innerWidth', true)
        ->assertScript("getComputedStyle(document.querySelector('.ei-stacked-table > thead')).display", 'none')
        ->assertScript("getComputedStyle(document.querySelector('.ei-stacked-table td[data-label]'), '::before').content !== 'none'", true)
        ->assertScript("(() => { const field = document.querySelector('.ei-visibility-search .fi-ta-search-field').getBoundingClientRect(); return Math.round(field.width) === document.querySelector('.ei-visibility-search').clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

it('opens the enforcement picker as a sheet along the bottom edge of a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    WorkspaceEmailBlocklist::factory()->protected()->email('legal@acme.example')->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(393, 852)
        ->navigate("/app/{$workspace->slug}/workspace/email/privacy")
        ->waitForText('legal@acme.example')
        ->click('.ei-enforcement-picker-trigger')
        ->wait(0.5)
        ->assertScript("(() => { const r = document.querySelector('[data-sheet]').getBoundingClientRect(); return r.left === 0 && r.right === innerWidth && Math.round(r.bottom) === innerHeight; })()", true)
        ->assertNoJavaScriptErrors();
});

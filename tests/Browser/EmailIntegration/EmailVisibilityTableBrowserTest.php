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
        ->assertScript("getComputedStyle(document.querySelector('.ei-visibility-table > thead')).display", 'none')
        ->assertScript("getComputedStyle(document.querySelector('.ei-visibility-table td[data-label]'), '::before').content !== 'none'", true)
        ->assertScript("(() => { const field = document.querySelector('.ei-visibility-search .fi-ta-search-field').getBoundingClientRect(); return Math.round(field.width) === document.querySelector('.ei-visibility-search').clientWidth; })()", true)
        ->assertNoJavaScriptErrors();
});

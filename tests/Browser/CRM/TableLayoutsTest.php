<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Models\Company;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;

mutates(HasTableLayouts::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('applies a layout from the toolbar menu and marks a change as modified', function (): void {
    TableLayout::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger')
        ->click('Hot leads')
        ->assertSeeIn('.fi-ta-layouts-trigger', 'Hot leads')
        ->assertDontSee('(modified)');

    $page->type('.fi-ta-search-field input', 'zzz')
        ->assertSee('(modified)')
        ->click('.fi-ta-layouts [wire\:click="revertTableLayout"]')
        ->assertDontSee('(modified)')
        ->assertNoJavaScriptErrors();
});

it('keeps the layout list in step with the search box after Escape', function (): void {
    foreach (range(1, 7) as $number) {
        TableLayout::factory()->for($this->user)->for($this->workspace)->create(['name' => "Layout {$number}"]);
    }

    $visibleRows = '[...document.querySelectorAll(".fi-ta-layouts-row")].filter((row) => row.offsetParent !== null).length';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger')
        ->type('.fi-ta-layouts-search input', 'Layout 3')
        ->assertScript($visibleRows, 1);

    $page->keys('.fi-ta-layouts-search input', 'Escape')
        ->assertScript('document.querySelector(".fi-ta-layouts-search input").offsetParent === null', true)
        ->click('.fi-ta-layouts-trigger')
        ->assertScript("{$visibleRows} === (document.querySelector('.fi-ta-layouts-search input').value === '' ? 7 : 1)", true)
        ->assertNoJavaScriptErrors();
});

it('closes a layout confirmation from its close button on a phone, where the layouts menu is a sheet', function (): void {
    $layout = TableLayout::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Hot leads']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger');

    $page->script(<<<'JS'
        document.querySelector(`.fi-ta-layouts-row [wire\\:click^="mountAction('deleteTableLayout'"]`).click()
    JS);

    $page->assertSee('Delete "Hot leads"?')
        ->click('.fi-modal.fi-modal-open .fi-modal-close-btn')
        ->assertScript('document.querySelector(".fi-modal.fi-modal-open") === null', true)
        ->assertNoJavaScriptErrors();

    expect(TableLayout::query()->whereKey($layout->getKey())->exists())->toBeTrue();
});

it('keeps a shared layout name readable beside a long owner name', function (): void {
    $owner = User::factory()->create(['name' => 'Maximiliana Alexandrova-Konstantinopoulou']);
    $this->workspace->users()->attach($owner, ['role' => 'member']);
    TableLayout::factory()->for($owner)->for($this->workspace)->shared()->create(['name' => 'Enterprise renewals']);

    $nameBox = 'document.querySelector(".fi-ta-layouts-row [title=\"Enterprise renewals\"]").getBoundingClientRect()';
    $panelBox = 'document.querySelector(".fi-ta-layouts-row").closest(".fi-dropdown-panel").getBoundingClientRect()';
    $ownerBox = 'document.querySelector(".fi-ta-layouts-row-meta").getBoundingClientRect()';

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger')
        ->assertScript("{$nameBox}.width >= 80", true)
        ->assertScript("{$ownerBox}.right <= {$panelBox}.right", true)
        ->assertNoJavaScriptErrors();
});

it('opens each layout row menu with that layout own options and acts on that layout', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $mine = TableLayout::factory()->for($member)->for($this->workspace)->shared()->create(['name' => 'Mine']);
    $theirs = TableLayout::factory()->for($this->user)->for($this->workspace)->shared()->create(['name' => 'Theirs']);

    $visibleRowMenuItems = '[...document.querySelectorAll(".fi-ta-layouts-row-menu .fi-dropdown-list-item")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())';

    $page = loginViaBrowser($member)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger');

    $page->hover('.fi-ta-layouts-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->assertScript($visibleRowMenuItems, ['Rename', 'Stop sharing', 'Set as my default', 'Delete layout']);

    $page->click('[aria-label="Actions for Mine"]');
    $page->hover('.fi-ta-layouts-row:has([title="Theirs"])')->click('[aria-label="Actions for Theirs"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default']);

    $page->script('[...document.querySelectorAll(".fi-ta-layouts-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null).click()');
    $page->wait(1)->assertNoJavaScriptErrors();

    expect(TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($member)->value('default_layout_id'))->toBe($theirs->getKey());

    $page->click('.fi-ta-layouts-trigger');
    $page->hover('.fi-ta-layouts-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->script('[...document.querySelectorAll(".fi-ta-layouts-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Rename").click()');
    $page->assertValue('.fi-modal.fi-modal-open input', 'Mine')
        ->assertNoJavaScriptErrors();

    expect($mine->refresh()->name)->toBe('Mine');

    $page->keys('.fi-modal.fi-modal-open input', 'Escape')
        ->click('.fi-ta-layouts-trigger')
        ->hover('.fi-ta-layouts-row:has([title="Mine"])')
        ->keys('[aria-label="Actions for Mine"]', 'Enter')
        ->assertScript('document.activeElement?.closest(".fi-ta-layouts-row-menu") !== null && document.activeElement.textContent.trim() === "Rename"', true);
});

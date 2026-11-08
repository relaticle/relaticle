<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Models\Company;
use App\Models\TableLayout;
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

<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;

mutates(HasSavedViews::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('applies a view from the toolbar menu and marks a change as modified', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger')
        ->click('Hot leads')
        ->assertSeeIn('.fi-ta-views-trigger', 'Hot leads')
        ->assertDontSee('(modified)');

    $page->type('.fi-ta-search-field input', 'zzz')
        ->assertSee('(modified)')
        ->click('.fi-ta-views [wire\:click="revertSavedView"]')
        ->assertDontSee('(modified)')
        ->assertNoJavaScriptErrors();
});

it('keeps the view list in step with the search box after Escape', function (): void {
    foreach (range(1, 7) as $number) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => "View {$number}"]);
    }

    $visibleRows = '[...document.querySelectorAll(".fi-ta-views-row")].filter((row) => row.offsetParent !== null).length';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger')
        ->type('.fi-ta-views-search input', 'View 3')
        ->assertScript($visibleRows, 1);

    $page->keys('.fi-ta-views-search input', 'Escape')
        ->assertScript('document.querySelector(".fi-ta-views-search input").offsetParent === null', true)
        ->click('.fi-ta-views-trigger')
        ->assertScript("{$visibleRows} === (document.querySelector('.fi-ta-views-search input').value === '' ? 7 : 1)", true)
        ->assertNoJavaScriptErrors();
});

it('closes a view confirmation from its close button on a phone, where the views menu is a sheet', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Hot leads']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger');

    $page->script(<<<'JS'
        document.querySelector(`.fi-ta-views-row [wire\\:click^="mountAction('deleteSavedView'"]`).click()
    JS);

    $page->assertSee('Delete "Hot leads"?')
        ->click('.fi-modal.fi-modal-open .fi-modal-close-btn')
        ->assertScript('document.querySelector(".fi-modal.fi-modal-open") === null', true)
        ->assertNoJavaScriptErrors();

    expect(SavedView::query()->whereKey($view->getKey())->exists())->toBeTrue();
});

it('keeps a shared view name readable beside a long owner name', function (): void {
    $owner = User::factory()->create(['name' => 'Maximiliana Alexandrova-Konstantinopoulou']);
    $this->workspace->users()->attach($owner, ['role' => 'member']);
    SavedView::factory()->for($owner)->for($this->workspace)->shared()->create(['name' => 'Enterprise renewals']);

    $nameBox = 'document.querySelector(".fi-ta-views-row [title=\"Enterprise renewals\"]").getBoundingClientRect()';
    $panelBox = 'document.querySelector(".fi-ta-views-row").closest(".fi-dropdown-panel").getBoundingClientRect()';
    $ownerBox = 'document.querySelector(".fi-ta-views-row-meta").getBoundingClientRect()';

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger')
        ->assertScript("{$nameBox}.width >= 80", true)
        ->assertScript("{$ownerBox}.right <= {$panelBox}.right", true)
        ->assertNoJavaScriptErrors();
});

it('opens each view row menu with that view own options and acts on that view', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $mine = SavedView::factory()->for($member)->for($this->workspace)->shared()->create(['name' => 'Mine']);
    $theirs = SavedView::factory()->for($this->user)->for($this->workspace)->shared()->create(['name' => 'Theirs']);

    $visibleRowMenuItems = '[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())';

    $page = loginViaBrowser($member)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger');

    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->assertScript($visibleRowMenuItems, ['Rename', 'Stop sharing', 'Set as my default', 'Delete view']);

    $page->click('[aria-label="Actions for Mine"]');
    $page->hover('.fi-ta-views-row:has([title="Theirs"])')->click('[aria-label="Actions for Theirs"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default']);

    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null).click()');
    $page->wait(1)->assertNoJavaScriptErrors();

    expect(TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($member)->value('default_view_id'))->toBe($theirs->getKey());

    $page->click('.fi-ta-views-trigger');
    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Rename").click()');
    $page->assertValue('.fi-modal.fi-modal-open input', 'Mine')
        ->assertNoJavaScriptErrors();

    expect($mine->refresh()->name)->toBe('Mine');

    $page->keys('.fi-modal.fi-modal-open input', 'Escape')
        ->click('.fi-ta-views-trigger')
        ->hover('.fi-ta-views-row:has([title="Mine"])')
        ->keys('[aria-label="Actions for Mine"]', 'Enter')
        ->assertScript('document.activeElement?.closest(".fi-ta-views-row-menu") !== null && document.activeElement.textContent.trim() === "Rename"', true);
});

it('hides a view group heading when the search matches none of its views', function (): void {
    foreach (range(1, 7) as $number) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => "Alpha {$number}"]);
    }

    $colleague = User::factory()->create();
    $this->workspace->users()->attach($colleague, ['role' => 'member']);
    SavedView::factory()->for($colleague)->for($this->workspace)->shared()->create(['name' => 'Zeta']);

    $visibleHeadings = '[...document.querySelectorAll(".fi-ta-views-heading")].filter((heading) => heading.offsetParent !== null).length';

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-views-trigger')
        ->assertScript($visibleHeadings, 2)
        ->type('.fi-ta-views-search input', 'Alpha')
        ->assertScript($visibleHeadings, 1)
        ->type('.fi-ta-views-search input', 'Zeta')
        ->assertScript($visibleHeadings, 1)
        ->assertNoJavaScriptErrors();
});

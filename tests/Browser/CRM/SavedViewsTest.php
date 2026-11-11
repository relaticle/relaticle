<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;

mutates(HasSavedViews::class);

function savedViewsWaitFor(string $condition): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}

function changeViewByRule(mixed $page, string $value = 'zzz'): void
{
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: '{$value}' }])");
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('applies a view from the switcher and shows no discard or save control after a change', function (int $width, int $height): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize($width, $height)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Hot leads');

    changeViewByRule($page);
    $page->assertScript(savedViewsWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true)
        ->assertDontSee('Discard changes')
        ->assertScript(filterRowSaveControlCount(), 0)
        ->assertNoJavaScriptErrors();
})->with([[1440, 900], [390, 844]]);

it('reads a view changed in one request back changed after a reload', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');

    changeViewByRule($page, 'acme');
    $page->assertScript(savedViewsWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true);

    expect(data_get($view->refresh()->state->filters, 'conditions.rules.0.value'))->toBe('acme');

    $page->navigate("/app/{$this->workspace->slug}/companies?view={$view->getKey()}")
        ->assertScript(savedViewsWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true)
        ->assertSeeIn('.fi-view-switcher-trigger', 'Hot leads')
        ->assertNoJavaScriptErrors();
});

it('keeps a tabbed-to chip control inside the strip on a phone', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Team pipeline');
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', Array.from({ length: 3 }, (_, index) => ({ field: 'name', condition: 'contains', value: 'chip' + index })))");
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 3', true);

    $inside = 'document.activeElement.matches("[data-probe-target]") && (() => { const strip = document.querySelector(".fi-filter-bar-start").getBoundingClientRect(); const box = document.activeElement.getBoundingClientRect(); return box.left >= strip.left + 31 && box.right <= strip.right - 31; })()';

    $page->script('document.querySelector(".fi-filter-bar-start").scrollLeft = 0; const chips = document.querySelectorAll("[data-filter-chip]"); chips[1].querySelector("[data-filter-more]").setAttribute("data-probe-prev", ""); chips[2].querySelector("[data-filter-segment=condition]").setAttribute("data-probe-cond", ""); chips[2].querySelector("button[data-filter-segment=value]").setAttribute("data-probe-value", ""); chips[2].querySelector("[data-filter-more]").setAttribute("data-probe-more", "")');
    $page->keys('[data-probe-prev]', 'Tab')
        ->keys('[data-probe-cond]', 'Tab')
        ->script('document.activeElement.setAttribute("data-probe-target", "")');
    $page->assertScript('document.activeElement === document.querySelector("[data-probe-value]")', true)
        ->assertScript(savedViewsWaitFor($inside), true);

    $page->script('document.activeElement.removeAttribute("data-probe-target")');
    $page->keys('[data-probe-value]', 'Tab')
        ->script('document.activeElement.setAttribute("data-probe-target", "")');
    $page->assertScript('document.activeElement === document.querySelector("[data-probe-more]")', true)
        ->assertScript(savedViewsWaitFor($inside), true)
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
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->type('.fi-ta-views-search input', 'View 3')
        ->assertScript($visibleRows, 1);

    $page->keys('.fi-ta-views-search input', 'Escape')
        ->assertScript('document.querySelector(".fi-ta-views-search input").offsetParent === null', true)
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript("{$visibleRows} === (document.querySelector('.fi-ta-views-search input').value === '' ? 7 : 1)", true)
        ->assertNoJavaScriptErrors();
});

it('closes a view confirmation from its close button on a phone, where the switcher is a sheet', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Hot leads']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row')->click('[aria-label="Actions for Hot leads"]');
    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Delete view").click()');

    $page->assertSee('Delete "Hot leads"?')
        ->click('.fi-modal.fi-modal-open .fi-modal-close-btn')
        ->assertScript('document.querySelector(".fi-modal.fi-modal-open") === null', true)
        ->assertNoJavaScriptErrors();

    expect(SavedView::query()->whereKey($view->getKey())->exists())->toBeTrue();
});

it('opens each view row menu with that view own options and acts on that view', function (): void {
    $mine = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Mine']);
    $other = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Other']);

    $visibleRowMenuItems = '[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default', 'Rename', 'Duplicate', 'Delete view']);

    $page->click('[aria-label="Actions for Mine"]');
    $page->hover('.fi-ta-views-row:has([title="Other"])')->click('[aria-label="Actions for Other"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default', 'Rename', 'Duplicate', 'Delete view']);

    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null).click()');
    $page->wait(1)->assertNoJavaScriptErrors();

    expect(TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($this->user)->value('default_view_id'))->toBe($other->getKey());

    $page->click('.fi-view-switcher .fi-dropdown-trigger button');
    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Rename").click()');
    $page->assertValue('.fi-modal.fi-modal-open input', 'Mine')
        ->assertNoJavaScriptErrors();

    expect($mine->refresh()->name)->toBe('Mine');

    $page->keys('.fi-modal.fi-modal-open input', 'Escape')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->hover('.fi-ta-views-row:has([title="Mine"])')
        ->keys('[aria-label="Actions for Mine"]', 'Enter')
        ->assertScript('document.activeElement?.closest(".fi-ta-views-row-menu") !== null && document.activeElement.textContent.trim() === "Set as my default"', true);
});

it('hides the view group heading when the search matches none of its views', function (): void {
    foreach (range(1, 7) as $number) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => "Alpha {$number}"]);
    }

    $visibleHeadings = '[...document.querySelectorAll(".fi-ta-views-heading")].filter((heading) => heading.offsetParent !== null).length';

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript($visibleHeadings, 1)
        ->type('.fi-ta-views-search input', 'Alpha')
        ->assertScript($visibleHeadings, 1)
        ->type('.fi-ta-views-search input', 'Zeta')
        ->assertScript($visibleHeadings, 0)
        ->assertNoJavaScriptErrors();
});

it('keeps a long view name truncated and opens its row menu inside the viewport', function (): void {
    $name = 'Quarterly enterprise renewals across every region with a signed order form and a named champion';
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => $name]);

    $row = 'document.querySelector(".fi-ta-views-row")';
    $viewportWidth = 'document.documentElement.clientWidth';
    $kebab = 'document.querySelector(".fi-ta-views-row [aria-label^=\\"Actions for\\"]").getBoundingClientRect()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript("{$row}.querySelector('[title]').scrollWidth > {$row}.querySelector('[title]').clientWidth", true);

    $page->hover('.fi-ta-views-row')
        ->click('.fi-ta-views-row [aria-label^="Actions for"]')
        ->assertScript('(() => { const box = document.querySelector(".fi-ta-views-row-menu").getBoundingClientRect(); return box.left >= 0 && box.right <= '.$viewportWidth.' && box.bottom <= window.innerHeight; })()', true)
        ->assertScript('document.querySelector(".fi-ta-views-row-menu").getBoundingClientRect().left >= document.querySelector(".fi-ta-views-panel").closest(".fi-dropdown-panel").getBoundingClientRect().right', true)
        ->assertScript("{$kebab}.right <= {$viewportWidth}", true)
        ->assertNoJavaScriptErrors();

    $page->resize(390, 844)
        ->assertScript("{$kebab}.right <= {$viewportWidth} && {$kebab}.left >= 0", true)
        ->assertNoJavaScriptErrors();
});

it('lands on the list with a saved view applied when it is picked from the board', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'name' => 'Open pipeline',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities/board")
        ->assertSeeIn('.fi-view-switcher-trigger', 'Board')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Open pipeline')
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->assertSeeIn('.fi-view-switcher-trigger', 'Open pipeline')
        ->assertNoJavaScriptErrors();
});

it('opens the built-in list from the board even when a view was last applied on the list', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'name' => 'Open pipeline',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Open pipeline')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Open pipeline')
        ->navigate("/app/{$this->workspace->slug}/opportunities/board")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('All opportunities')
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->assertSeeIn('.fi-view-switcher-trigger', 'All opportunities')
        ->assertScript('location.search', '')
        ->assertNoJavaScriptErrors();
});

it('keeps a descending sort when a view sorted that way is picked from an unsorted list', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Newest names',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Newest names');

    $page->assertScript("document.querySelector('[data-filter-sort]')?.getAttribute('aria-label') === 'Sorted by Company, descending'", true)
        ->assertScript("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.tableSort", 'name:desc')
        ->assertDontSee('Discard changes')
        ->assertNoJavaScriptErrors();
});

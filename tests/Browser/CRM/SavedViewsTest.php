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

    $visibleRows = '[...document.querySelectorAll(".fi-view-switcher .fi-ta-views-row")].filter((row) => row.offsetParent !== null).length';

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
        ->assertScript("{$visibleRows} === (document.querySelector('.fi-ta-views-search input').value === '' ? 8 : 1)", true)
        ->assertNoJavaScriptErrors();
});

it('closes a view confirmation from its close button on a phone, where the switcher is a sheet', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Hot leads']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row:has([title="Hot leads"])')->click('[aria-label="Actions for Hot leads"]');
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
    $page->assertScript($visibleRowMenuItems, ['Move up', 'Move down', 'Rename', 'Duplicate', 'Delete view']);

    $page->click('[aria-label="Actions for Mine"]');
    $page->hover('.fi-ta-views-row:has([title="Other"])')->click('[aria-label="Actions for Other"]');
    $page->assertScript($visibleRowMenuItems, ['Move up', 'Move down', 'Rename', 'Duplicate', 'Delete view']);

    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Move up").click()');
    waitForStoredOrder($page, $this->user, CrmEntity::Company, ['list', $other->getKey(), $mine->getKey()]);
    $page->assertNoJavaScriptErrors();

    expect(storedViewOrder($this->user, CrmEntity::Company))->toBe(['list', $other->getKey(), $mine->getKey()]);

    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null && item.textContent.trim() === "Rename").click()');
    $page->assertValue('.fi-modal.fi-modal-open input', 'Mine')
        ->assertNoJavaScriptErrors();

    expect($mine->refresh()->name)->toBe('Mine');

    $page->keys('.fi-modal.fi-modal-open input', 'Escape')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->hover('.fi-ta-views-row:has([title="Mine"])')
        ->keys('[aria-label="Actions for Mine"]', 'Enter')
        ->assertScript('document.activeElement?.closest(".fi-ta-views-row-menu") !== null && document.activeElement.textContent.trim() === "Move up"', true);
});

it('tells the member when the search matches no view', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Alpha']);

    $empty = 'document.querySelector(".fi-ta-views-empty").offsetParent !== null';

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript($empty, false)
        ->type('.fi-ta-views-search input', 'Zeta')
        ->assertScript($empty, true)
        ->type('.fi-ta-views-search input', 'Alpha')
        ->assertScript($empty, false)
        ->assertNoJavaScriptErrors();
});

it('keeps a long view name truncated and opens its row menu inside the viewport', function (): void {
    $name = 'Quarterly enterprise renewals across every region with a signed order form and a named champion';
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => $name]);

    $row = 'document.querySelector(".fi-ta-views-row:has([title^=\\"Quarterly\\"])")';
    $viewportWidth = 'document.documentElement.clientWidth';
    $kebab = 'document.querySelector(".fi-ta-views-row [aria-label^=\\"Actions for Quarterly\\"]").getBoundingClientRect()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript("{$row}.querySelector('[title]').scrollWidth > {$row}.querySelector('[title]').clientWidth", true);

    $page->hover('.fi-ta-views-row:has([title^="Quarterly"])')
        ->click('.fi-ta-views-row [aria-label^="Actions for Quarterly"]')
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
        ->assertSeeIn('.fi-view-switcher-trigger', 'Pipeline')
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
        ->navigate("/app/{$this->workspace->slug}/opportunities?view=all")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Open pipeline')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Open pipeline')
        ->navigate("/app/{$this->workspace->slug}/opportunities/board")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('All opportunities')
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->assertSeeIn('.fi-view-switcher-trigger', 'All opportunities')
        ->assertScript('location.search', '?view=all')
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

function switcherKeys(): string
{
    return '[...document.querySelectorAll(".fi-view-switcher .fi-ta-views-row")].map((row) => row.dataset.viewKey)';
}

function waitForStoredOrder(mixed $page, User $user, CrmEntity $entity, array $expected): void
{
    for ($attempt = 0; $attempt < 50 && storedViewOrder($user, $entity) !== $expected; $attempt++) {
        $page->wait(0.1);
    }
}

function storedViewOrder(User $user, CrmEntity $entity): ?array
{
    return TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($user)->where('entity', $entity)->value('view_order');
}

it('shows the search, a tile per kind and the rows in order with the pipeline first on opportunities', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Opportunity, 'name' => 'Open pipeline']);

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities/board")
        ->assertSeeIn('.fi-view-switcher-trigger', 'Pipeline')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertVisible('.fi-ta-views-search input')
        ->assertScript(switcherKeys(), ['board', 'list', $view->getKey()])
        ->assertScript('[...document.querySelectorAll(".fi-view-switcher .fi-ta-views-row .fi-view-tile")].map((tile) => tile.className.split(" ").pop())', ['fi-view-tile-orange', 'fi-view-tile-success', 'fi-view-tile-success'])
        ->assertScript('[...document.querySelectorAll(".fi-view-switcher .fi-ta-views-row")].map((row) => row.textContent.trim().replace(/\s+/g, " "))', ['Pipeline', 'All opportunities', 'Open pipeline'])
        ->assertNoJavaScriptErrors();
});

it('opens the list when a member who put the table first visits opportunities', function (): void {
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Opportunity, 'view_order' => ['list', 'board']]);

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->assertSeeIn('.fi-view-switcher-trigger', 'All opportunities')
        ->assertNoJavaScriptErrors();
});

it('reorders by dragging a row and keeps the order after a reload', function (): void {
    $alpha = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Alpha']);
    $beta = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Beta']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript(switcherKeys(), ['list', $alpha->getKey(), $beta->getKey()]);

    $page->drag("[data-view-key=\"{$beta->getKey()}\"] .fi-dropdown-list-item", '[data-view-key="list"] .fi-dropdown-list-item');
    waitForStoredOrder($page, $this->user, CrmEntity::Company, [$beta->getKey(), 'list', $alpha->getKey()]);

    expect(storedViewOrder($this->user, CrmEntity::Company))->toBe([$beta->getKey(), 'list', $alpha->getKey()]);

    $page->navigate("/app/{$this->workspace->slug}/companies?view=all")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript(switcherKeys(), [$beta->getKey(), 'list', $alpha->getKey()])
        ->assertNoJavaScriptErrors();
});

it('moves a row from its menu and disables the move that has nowhere to go', function (): void {
    $alpha = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Alpha']);
    $beta = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Beta']);

    $item = fn (string $label): string => '[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "'.$label.'")';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row:has([title="Beta"])')->click('[aria-label="Actions for Beta"]');
    $page->assertScript($item('Move down').'.disabled', true)
        ->assertScript($item('Move up').'.disabled', false);

    $page->script($item('Move up').'.click()');
    waitForStoredOrder($page, $this->user, CrmEntity::Company, ['list', $beta->getKey(), $alpha->getKey()]);

    expect(storedViewOrder($this->user, CrmEntity::Company))->toBe(['list', $beta->getKey(), $alpha->getKey()]);

    $page->assertScript(switcherKeys(), ['list', $beta->getKey(), $alpha->getKey()])
        ->assertVisible('.fi-ta-views-search input')
        ->assertScript('document.activeElement === document.querySelector(\'[aria-label="Actions for Beta"]\')', true);

    $page->hover('.fi-ta-views-row:has([title="All companies"])')->click('[aria-label="Actions for All companies"]');
    $page->assertScript($item('Move up').'.disabled', true)
        ->assertNoJavaScriptErrors();
});

it('narrows the rows by name and takes no part in dragging while a query is typed', function (): void {
    $views = collect(['Alpha one', 'Alpha two', 'Beta'])->map(fn (string $name): SavedView => SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => $name]));

    $visible = '[...document.querySelectorAll(".fi-view-switcher .fi-ta-views-row")].filter((row) => row.offsetParent !== null).map((row) => row.dataset.viewName)';
    $draggable = 'document.querySelectorAll(".fi-ta-views-draggable").length';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript($draggable, 4)
        ->type('.fi-ta-views-search input', 'alpha')
        ->assertScript($visible, ['alpha one', 'alpha two'])
        ->assertScript($draggable, 0);

    $page->drag("[data-view-key=\"{$views[1]->getKey()}\"] .fi-dropdown-list-item", "[data-view-key=\"{$views[0]->getKey()}\"] .fi-dropdown-list-item");
    $page->wait(1)
        ->assertScript(switcherKeys(), ['list', $views[0]->getKey(), $views[1]->getKey(), $views[2]->getKey()])
        ->assertNoJavaScriptErrors();

    expect(storedViewOrder($this->user, CrmEntity::Company))->toBeNull();
});

it('keeps opportunities on the list when the built-in list is reloaded', function (): void {
    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities?view=all")
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->refresh()
        ->assertPathIs("/app/{$this->workspace->slug}/opportunities")
        ->assertSeeIn('.fi-view-switcher-trigger', 'All opportunities')
        ->assertNoJavaScriptErrors();
});

it('returns focus to the trigger when Escape closes the switcher', function (): void {
    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->keys('.fi-ta-views-search input', 'Escape')
        ->assertScript('document.activeElement === document.querySelector(".fi-view-switcher-trigger")', true)
        ->assertNoJavaScriptErrors();
});

it('keeps the switcher usable as a sheet on a phone', function (): void {
    $alpha = SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Alpha', 'state' => new TableStateData(sort: 'name:desc')]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertVisible('.fi-ta-views-search input')
        ->assertScript('(() => { const box = document.querySelector(".fi-ta-views-panel").getBoundingClientRect(); return box.left >= 0 && box.right <= document.documentElement.clientWidth && box.bottom <= window.innerHeight; })()', true)
        ->assertScript(switcherKeys(), ['list', $alpha->getKey()]);

    $page->click('Alpha')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Alpha')
        ->assertNoJavaScriptErrors();
});

it('opens a view with a plain click on its row', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Alpha', 'state' => new TableStateData(sort: 'name:desc')]);

    loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-row:has([title="Alpha"]) .fi-dropdown-list-item')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Alpha')
        ->assertNoJavaScriptErrors();
});

it('keeps a row in its hover look while its menu is open', function (): void {
    foreach (['Alpha', 'Beta'] as $name) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => $name]);
    }

    $background = fn (string $name): string => 'getComputedStyle(document.querySelector(\'.fi-ta-views-row:has([title="'.$name.'"])\')).backgroundColor';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row:has([title="Beta"])');
    $hoverBackground = $page->script($background('Beta'));
    $hoverKebab = $page->script('getComputedStyle(document.querySelector(\'[aria-label="Actions for Beta"] .fi-icon\')).color');

    $page->hover('.fi-ta-views-row:has([title="Alpha"])')->click('[aria-label="Actions for Alpha"]');
    $page->hover('.fi-ta-views-search input');

    $page->assertScript($background('Alpha'), $hoverBackground)
        ->assertScript($background('Beta'), 'rgba(0, 0, 0, 0)')
        ->assertScript('getComputedStyle(document.querySelector(\'[aria-label="Actions for Alpha"] .fi-icon\')).color', $hoverKebab)
        ->assertNoJavaScriptErrors();
});

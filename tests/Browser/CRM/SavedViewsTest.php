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

it('applies a view from the switcher and offers to discard or save a change', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Hot leads')
        ->assertDontSee('Discard changes');

    changeViewByRule($page);
    $page->assertSee('Discard changes')
        ->assertScript('(() => { const bar = document.querySelector("[data-filter-bar]"); const row = bar.getBoundingClientRect(); const save = document.querySelector("[data-view-save]").getBoundingClientRect(); const padding = parseFloat(getComputedStyle(bar).paddingRight); return save.top >= row.top && save.bottom <= row.bottom && Math.abs(save.right - (row.right - padding)) <= 1; })()', true)
        ->click('[data-view-save-action="discard"]')
        ->assertDontSee('Discard changes')
        ->assertNoJavaScriptErrors();
});

it('offers save as new view from the arrow beside save for everyone on a shared view', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'is_shared' => true,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Team pipeline');
    changeViewByRule($page);
    $page->assertSeeIn('[data-view-save]', 'Save for everyone')
        ->click('[aria-label="More ways to save"]')
        ->click('[data-view-save] .fi-dropdown-list-item[data-view-save-action="save-as"]')
        ->assertSee('Share with workspace')
        ->assertNoJavaScriptErrors();
});

it('joins the save halves into one chip-height control that stays pinned beside a long row of chips', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    changeViewByRule($page);
    $page->assertSee('Discard changes');

    $measure = <<<'JS'
        (() => {
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();
            const save = box('.fi-view-save-split > .fi-btn');
            const more = box('.fi-view-save-more');
            const discard = box('.fi-view-save-discard');
            const chip = box('[data-filter-chip]');

            return {
                sameHeight: save.height === more.height && more.height === chip.height && discard.height === chip.height,
                sameTop: save.top === more.top,
                touching: Math.abs(save.right - more.left) <= 0.5,
                square: getComputedStyle(document.querySelector('.fi-view-save-more')).borderStartStartRadius === '0px',
                right: Math.round(more.right),
            };
        })();
    JS;

    $before = $page->script($measure);
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', Array.from({ length: 8 }, (_, index) => ({ field: 'name', condition: 'contains', value: 'chip' + index })))");
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true);
    $after = $page->script($measure);

    expect($before)->toMatchArray(['sameHeight' => true, 'sameTop' => true, 'touching' => true, 'square' => true])
        ->and($after)->toBe($before);
    $page->assertNoJavaScriptErrors();
});

it('draws the keyboard focus ring of each save half over the half beside it', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'is_shared' => true,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);
    $focusedHalfIsAbove = 'document.activeElement.matches(":focus-visible") && Number(getComputedStyle(document.activeElement).zIndex) > 0';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Team pipeline');
    changeViewByRule($page);
    $page->assertSee('Save for everyone');

    if (! str_contains((string) $page->script('navigator.userAgent'), 'Chrome')) {
        $this->markTestSkipped('Safari never matches :focus-visible on a button reached without a real Tab key.');
    }

    $page->script('document.querySelector("[data-view-save-action=save]").focus()');
    $page->keys('[data-view-save-action="save"]', 'Shift')
        ->assertScript('document.activeElement === document.querySelector("[data-view-save-action=save]")', true)
        ->assertScript($focusedHalfIsAbove, true);

    $page->script('document.querySelector(".fi-view-save-more").focus()');
    $page->keys('.fi-view-save-more', 'Shift')
        ->assertScript('document.activeElement === document.querySelector(".fi-view-save-more")', true)
        ->assertScript($focusedHalfIsAbove, true)
        ->assertNoJavaScriptErrors();
});

it('leaves the chip strip room on a phone with an icon discard beside save for everyone', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'is_shared' => true,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Team pipeline');
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', Array.from({ length: 3 }, (_, index) => ({ field: 'name', condition: 'contains', value: 'chip' + index })))");
    $page->assertSeeIn('[data-view-save]', 'Save for everyone')
        ->assertScript('document.querySelectorAll("[data-filter-chip]").length === 3', true)
        ->assertScript('document.querySelector(".fi-filter-bar-start").getBoundingClientRect().width >= 120', true)
        ->assertScript('document.querySelectorAll("[data-view-save-action=discard]").length === 1', true)
        ->assertScript('(() => { const discard = document.querySelector("[data-view-save-action=discard]"); const icon = discard.querySelector(".fi-view-save-discard-icon"); return discard.textContent.trim() === "Discard changes" && icon.checkVisibility() && discard.querySelector(".fi-view-save-discard-text").getBoundingClientRect().width <= 1 && discard.getBoundingClientRect().height === document.querySelector("[data-filter-chip]").getBoundingClientRect().height; })()', true);

    $page->script('document.querySelector(".fi-filter-bar-start").scrollLeft = 99999');
    $page->script('window.probeMore = [...document.querySelectorAll("[data-filter-chip] [data-filter-more]")].at(-1); window.probeMore.click()');
    $page->assertScript(savedViewsWaitFor('(() => { const pop = document.querySelector("[data-pop][data-open=true]"); return Boolean(pop?.checkVisibility() && pop.contains(document.activeElement)); })()'), true)
        ->keys('[data-pop][data-open=true] [data-pop-item]', 'Escape')
        ->assertScript(savedViewsWaitFor('document.activeElement === window.probeMore'), true)
        ->click('[data-view-save-action="discard"]')
        ->assertScript('(async () => { for (let attempt = 0; attempt < 50; attempt++) { if (document.querySelectorAll("[data-filter-chip]").length === 0) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()', true)
        ->assertNoJavaScriptErrors();
});

it('keeps a tabbed-to chip control inside the strip on a phone', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'is_shared' => true,
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

it('shows the discard tooltip only below the small breakpoint, following a resize', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    changeViewByRule($page);

    $tooltip = 'getComputedStyle(document.querySelector("[data-view-save-action=discard]"), "::after").content';

    $page->hover('[data-view-save-action="discard"]')
        ->assertScript("{$tooltip} === 'none'", true)
        ->resize(390, 844)
        ->hover('[data-view-save-action="discard"]')
        ->assertScript(savedViewsWaitFor("{$tooltip} === '\"Discard changes\"'"), true)
        ->resize(1440, 900)
        ->hover('[data-view-save-action="discard"]')
        ->assertScript(savedViewsWaitFor("{$tooltip} === 'none'"), true);
});

it('keeps the text discard button at desktop width', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    changeViewByRule($page);
    $page->assertSeeIn('[data-view-save-action="discard"]', 'Discard changes')
        ->assertScript('!document.querySelector(".fi-view-save-discard-icon").checkVisibility() && document.querySelector(".fi-view-save-discard-text").checkVisibility()', true);
});

it('keeps the save controls in the filter row on a phone without overflowing', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    changeViewByRule($page);
    $page->assertSee('Discard changes')
        ->assertPresent('[data-filter-bar] [data-view-save]')
        ->assertScript('document.querySelector("[data-view-save]").getBoundingClientRect().bottom <= document.querySelector(".fi-ta-content-ctn").getBoundingClientRect().top', true)
        ->assertScript('(() => { const bar = document.querySelector("[data-filter-bar]"); const row = bar.getBoundingClientRect(); const save = document.querySelector("[data-view-save]").getBoundingClientRect(); const sort = document.querySelector("[data-filter-sort]").getBoundingClientRect(); const padding = parseFloat(getComputedStyle(bar).paddingRight); return Math.abs((save.top + save.bottom) / 2 - (sort.top + sort.bottom) / 2) <= 1 && Math.abs(save.right - (row.right - padding)) <= 1; })()', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
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
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
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
        ->click('.fi-view-switcher .fi-dropdown-trigger button');

    $page->hover('.fi-ta-views-row:has([title="Mine"])')->click('[aria-label="Actions for Mine"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default', 'Rename', 'Duplicate', 'Stop sharing', 'Delete view']);

    $page->click('[aria-label="Actions for Mine"]');
    $page->hover('.fi-ta-views-row:has([title="Theirs"])')->click('[aria-label="Actions for Theirs"]');
    $page->assertScript($visibleRowMenuItems, ['Set as my default', 'Duplicate']);

    $page->script('[...document.querySelectorAll(".fi-ta-views-row-menu .fi-dropdown-list-item")].find((item) => item.offsetParent !== null).click()');
    $page->wait(1)->assertNoJavaScriptErrors();

    expect(TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($member)->value('default_view_id'))->toBe($theirs->getKey());

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
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript($visibleHeadings, 2)
        ->type('.fi-ta-views-search input', 'Alpha')
        ->assertScript($visibleHeadings, 1)
        ->type('.fi-ta-views-search input', 'Zeta')
        ->assertScript($visibleHeadings, 1)
        ->assertNoJavaScriptErrors();
});

it('keeps a long view name beside a long owner name truncated and opens its row menu inside the viewport', function (): void {
    $owner = User::factory()->create(['name' => 'Maximiliana Alexandrova-Konstantinopoulou-Papadopoulos']);
    $this->workspace->users()->attach($owner, ['role' => 'member']);
    $name = 'Quarterly enterprise renewals across every region with a signed order form and a named champion';
    SavedView::factory()->for($owner)->for($this->workspace)->shared()->create(['name' => $name]);

    $row = 'document.querySelector(".fi-ta-views-row")';
    $viewportWidth = 'document.documentElement.clientWidth';
    $kebab = 'document.querySelector(".fi-ta-views-row [aria-label^=\\"Actions for\\"]").getBoundingClientRect()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->assertScript("{$row}.querySelector('[title]').scrollWidth > {$row}.querySelector('[title]').clientWidth", true)
        ->assertScript("{$row}.querySelector('.fi-ta-views-row-meta').getBoundingClientRect().right <= {$row}.closest('.fi-dropdown-panel').getBoundingClientRect().right", true);

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

it('returns focus to the view switcher after discarding or saving from the keyboard', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);
    $onSwitcher = 'document.activeElement === document.querySelector(".fi-view-switcher-trigger") && document.activeElement !== document.body';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    changeViewByRule($page);
    $page->assertSee('Discard changes');

    $page->script('document.querySelector("[data-view-save-action=discard]").focus()');
    $page->keys('[data-view-save-action="discard"]', 'Enter')
        ->assertDontSee('Discard changes')
        ->assertScript($onSwitcher, true);

    changeViewByRule($page, 'yyy');
    $page->assertSee('Discard changes');
    $page->script('document.querySelector("[data-view-save-action=save]").focus()');
    $page->keys('[data-view-save-action="save"]', 'Enter')
        ->assertDontSee('Discard changes')
        ->assertScript($onSwitcher, true)
        ->assertNoJavaScriptErrors();
});

it('returns focus to the view switcher once the confirmation for saving over a shared view closes', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    SavedView::factory()->for($member)->for($this->workspace)->create([
        'name' => 'Team pipeline',
        'is_shared' => true,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Team pipeline');
    changeViewByRule($page);
    $page->click('[data-view-save-action="save"]')
        ->assertSee('Save over "Team pipeline"?');

    $page->click('.fi-modal-footer-actions .fi-color-primary')
        ->assertDontSee('Save over "Team pipeline"?')
        ->assertScript('document.activeElement === document.querySelector(".fi-view-switcher-trigger")', true)
        ->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

mutates(HasFilterBar::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    foreach (['Acme Robotics', 'Globex', 'Initech'] as $name) {
        Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]);
    }

    $this->open = fn (int $width = 1440, int $height = 900): AwaitableWebpage => visitAs($this->user, "/app/{$this->workspace->slug}/companies", $width, $height)
        ->assertPresent('[data-filter-add]');
});

it('marks the table busy while a filter change is in flight and clears it when the response lands', function (): void {
    $page = ($this->open)();
    expect(companyRows($page, 3))->toBeTrue();

    $page->script('window.fetch = ((original) => (input, init) => String(init?.body).includes("setFilterRules") ? new Promise((resolve) => setTimeout(resolve, 1500)).then(() => original(input, init)) : original(input, init))(window.fetch)');

    $busy = 'document.querySelector(".fi-ta").getAttribute("aria-busy") === "true" && document.querySelector(".fi-ta").classList.contains("fi-filter-busy")';

    addChip($page, 'name', 'contains')->type(valueInput(), 'acme');
    $page->assertScript(filterBarWaitFor($busy), true)
        ->assertScript(filterBarWaitFor('!document.querySelector(".fi-ta").hasAttribute("aria-busy") && !document.querySelector(".fi-ta").classList.contains("fi-filter-busy")'), true);
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('marks the table busy while a sort change from the bar is in flight and clears it when the response lands', function (): void {
    $page = ($this->open)();
    expect(companyRows($page, 3))->toBeTrue();

    $page->script('window.fetch = ((original) => (input, init) => String(init?.body).includes("setFilterBarSorts") ? new Promise((resolve) => setTimeout(resolve, 1500)).then(() => original(input, init)) : original(input, init))(window.fetch)');

    $busy = 'document.querySelector(".fi-ta").getAttribute("aria-busy") === "true" && document.querySelector(".fi-ta").classList.contains("fi-filter-busy")';

    $page->click('[data-filter-sort]')
        ->click('[data-sort-column][data-column="name"]')
        ->assertScript(filterBarWaitFor($busy), true)
        ->assertScript(filterBarWaitFor('!document.querySelector(".fi-ta").hasAttribute("aria-busy") && !document.querySelector(".fi-ta").classList.contains("fi-filter-busy")'), true)
        ->assertNoJavaScriptErrors();
});

it('names a chip after an attribute whose name holds a replacement pattern', function (): void {
    resolve(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Cost $& total', 'code' => 'cost_total', 'type' => 'text']);

    $page = ($this->open)();
    addChip($page, 'custom_fields.cost_total', 'contains')->type(valueInput(), 'x');

    $page->assertScript('document.querySelector("[data-filter-more]").getAttribute("aria-label").includes("Cost $& total")', true)
        ->assertScript('document.querySelector("[data-filter-chip]").getAttribute("aria-label").includes("Cost $& total")', true)
        ->assertNoJavaScriptErrors();
});

it('gives a chip value the full text it shows', function (): void {
    $page = ($this->open)();

    addChip($page, 'name', 'contains')->type(valueInput(), 'Quarterly enterprise renewals across every region');
    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-segment=value]").getAttribute("title") === "Quarterly enterprise renewals across every region"'), true)
        ->assertNoJavaScriptErrors();
});

it('draws a dashed sort and a dashed filter button when no filter is set', function (): void {
    ($this->open)()
        ->assertSeeIn('[data-filter-bar]', 'Sort')
        ->assertSeeIn('[data-filter-add]', 'Filter')
        ->assertMissing('[data-filter-sort][disabled]')
        ->assertScript('getComputedStyle(document.querySelector("[data-filter-sort]")).borderStyle === "dashed"', true)
        ->assertScript('getComputedStyle(document.querySelector("[data-filter-add]")).borderStyle === "dashed"', true)
        ->assertNoJavaScriptErrors();
});

it('adds a name chip, narrows the list, edits the value, changes the condition and removes it', function (): void {
    $page = ($this->open)();
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'name', 'contains')->type(valueInput(), 'acme');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSeeIn('[data-filter-chip]', 'acme')
        ->assertSee('Acme Robotics');

    $page->type(valueInput(), 'glo');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Globex');

    $page->click('[data-filter-segment="condition"]')
        ->click('[data-condition="does_not_contain"]');
    expect(companyRows($page, 2))->toBeTrue();
    $page->assertDontSee('Globex');

    $page->click('[data-filter-more]')
        ->click('[data-filter-remove]')
        ->assertMissing('[data-filter-chip]')
        ->assertSeeIn('[data-filter-add]', 'Filter');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('leaves the list and the view alone while a chip is unfinished', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'created_at:asc'),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'name', 'contains')
        ->assertPresent('[data-filter-chip].fi-pending')
        ->assertSeeIn('[data-filter-chip]', 'Select value');
    expect(companyRows($page, 3))->toBeTrue()
        ->and(data_get(SavedView::query()->sole()->state->filters, 'conditions.rules', []))->toBe([]);
});

it('saves a complete chip on a saved view as it completes and keeps the chip after a reload', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'created_at:asc'),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');

    addChip($page, 'name', 'contains')->type(valueInput(), 'glo');
    $page->assertScript(filterRowSaveControlCount(), 0);
    expect(companyRows($page, 1))->toBeTrue()
        ->and(data_get($view->refresh()->state->filters, 'conditions.rules.0.value'))->toBe('glo');

    TablePreference::query()->withoutGlobalScopes()->delete();

    $page->navigate("/app/{$this->workspace->slug}/companies?view={$view->getKey()}")
        ->assertSeeIn('[data-filter-chip]', 'glo')
        ->assertScript(filterRowSaveControlCount(), 0);
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('tells an empty list which filters hid its records and clears them', function (): void {
    $page = ($this->open)();

    addChip($page, 'name', 'contains')->type(valueInput(), 'zzqx');
    $page->assertSee('No companies match this view')
        ->click('Clear filters')
        ->assertMissing('[data-filter-chip]')
        ->assertDontSee('No companies match this view');
    expect(companyRows($page, 3))->toBeTrue();
});

it('adds a chip with the keyboard alone and returns focus to the control that opened each list', function (): void {
    $page = ($this->open)();

    $page->script('document.querySelector("[data-filter-add]").focus()');
    $page->keys('[data-filter-add]', 'Enter')
        ->assertScript(chipFocus('[data-filter-find]'), true)
        ->keys('[data-filter-find]', 'Escape')
        ->assertScript(chipFocus('[data-filter-add]'), true)
        ->keys('[data-filter-add]', 'Enter')
        ->type('[data-filter-find]', 'Compan')
        ->keys('[data-filter-find]', 'Enter')
        ->assertScript(chipFocus('[data-condition="contains"]'), true)
        ->keys('[data-pop-item]:focus', 'ArrowDown')
        ->keys('[data-pop-item]:focus', 'ArrowDown')
        ->assertScript(chipFocus('[data-condition="starts_with"]'), true)
        ->keys('[data-condition="starts_with"]', 'Enter')
        ->assertScript(chipFocus(valueInput()), true)
        ->type(valueInput(), 'gl')
        ->keys(valueInput(), 'Enter')
        ->assertScript(chipFocus('[data-filter-segment="value"]'), true);

    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Globex')->assertNoJavaScriptErrors();
});

it('reaches every chip control with Tab and names each one', function (): void {
    $page = ($this->open)();
    addChip($page, 'name', 'contains')->type(valueInput(), 'glo');
    $page->keys(valueInput(), 'Enter');
    expect(companyRows($page, 1))->toBeTrue();

    $page->assertScript('[...document.querySelectorAll("[data-filter-bar] button:not([disabled])")].filter((button) => button.checkVisibility()).every((button) => button.tabIndex === 0)', true)
        ->assertScript('[...document.querySelectorAll("[data-filter-bar] button:not([disabled])")].filter((button) => button.checkVisibility()).every((button) => (button.getAttribute("aria-label") ?? button.textContent).trim() !== "")', true)
        ->assertScript('document.querySelector("[data-filter-chip]").getAttribute("aria-label") === "Company contains glo"', true);
});

it('closes a list on Escape and a click away without trapping focus', function (): void {
    $page = ($this->open)();

    $page->click('[data-filter-add]')
        ->assertVisible('[data-filter-find]')
        ->click('[data-filter-bar]')
        ->assertMissing('[data-pop]:not([style*="display: none"])')
        ->click('[data-filter-add]')
        ->assertVisible('[data-filter-find]')
        ->keys('[data-filter-find]', 'Escape')
        ->assertMissing('[data-pop]:not([style*="display: none"])')
        ->click('[data-filter-add]')
        ->assertVisible('[data-filter-find]');

    $page->script('(() => { const items = document.querySelectorAll("[data-pop]:not([style*=\\"display: none\\"]) [data-pop-item]"); items[items.length - 1].setAttribute("data-last", ""); })()');
    $page->keys('[data-last]', 'Tab')
        ->assertMissing('[data-pop]:not([style*="display: none"])')
        ->assertNoJavaScriptErrors();
});

it('keeps the phone keyboard closed and the search at 16px when a filter menu opens', function (): void {
    $page = ($this->open)(390, 664);

    $page->click('[data-filter-add]')
        ->assertVisible('[data-filter-find]')
        ->assertScript('getComputedStyle(document.querySelector("[data-filter-find]")).fontSize', '16px')
        ->assertScript(filterBarWaitFor('document.activeElement.closest("[data-pop-item]") !== null'), true)
        ->assertScript('document.activeElement.tagName !== "INPUT"', true)
        ->assertNoJavaScriptErrors();
});

it('opens a chip list as a sheet on a phone with every option inside it', function (): void {
    $page = ($this->open)(390, 664);

    $page->click('[data-filter-add]')
        ->assertVisible('[data-filter-find]');

    $inSheet = <<<'JS'
        (() => {
            const sheet = document.querySelector('[data-filter-bar] [data-pop]:not([style*="display: none"])');
            const box = sheet.getBoundingClientRect();
            const options = [...sheet.querySelectorAll('[data-pop-item]')];
            const last = options.at(-1);

            last.scrollIntoView({ block: 'nearest' });

            const lastBox = last.getBoundingClientRect();
            const hit = document.elementFromPoint(lastBox.left + lastBox.width / 2, lastBox.top + lastBox.height / 2);

            return {
                isSheet: getComputedStyle(sheet).position === 'fixed' && Math.round(box.width) === innerWidth,
                options: options.length > 0,
                inside: lastBox.bottom <= innerHeight && lastBox.top >= 0 && sheet.contains(last),
                reachable: last.contains(hit),
            };
        })();
    JS;

    expect($page->script($inSheet))->toBe(['isSheet' => true, 'options' => true, 'inside' => true, 'reachable' => true]);
});

it('keeps the filter row inside a phone viewport with several chips', function (): void {
    $page = ($this->open)(390, 844);

    addChip($page, 'name', 'contains')->type(valueInput(), 'a');
    $page->keys(valueInput(), 'Enter');
    addChip($page, 'name', 'does_not_contain')->type(valueInput(), 'zz');
    $page->keys(valueInput(), 'Enter')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

it('keeps the filter row on one scrolling line however many chips it holds', function (int $width, int $height): void {
    $page = ($this->open)($width, $height);
    setRules($page, 1);
    $page->assertPresent('[data-filter-chip]');
    $single = $page->script('document.querySelector("[data-filter-bar]").getBoundingClientRect().height');

    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->assertScript("document.querySelector('[data-filter-bar]').getBoundingClientRect().height === {$single}", true)
        ->assertScript('(() => { const strip = document.querySelector(".fi-filter-bar-start"); return strip.scrollWidth > strip.clientWidth && strip.offsetHeight === strip.clientHeight && [...strip.querySelectorAll("[data-filter-chip]")].every((chip) => Math.abs(chip.getBoundingClientRect().top - strip.querySelector("[data-filter-sort]").getBoundingClientRect().top) < 2); })()', true)
        ->assertNoJavaScriptErrors();
})->with([[1440, 900], [390, 844]]);

it('scrolls the add button into view when its field list opens', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->script('document.querySelector(".fi-filter-bar-start").scrollLeft = 0');

    $page->script('document.querySelector("[data-filter-add]").click()');

    $page->assertScript(filterBarWaitFor('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()'), true)
        ->assertScript('(() => { const strip = document.querySelector(".fi-filter-bar-start").getBoundingClientRect(); const add = document.querySelector("[data-filter-add]").getBoundingClientRect(); return add.left >= strip.left && add.right <= strip.right; })()', true)
        ->assertNoJavaScriptErrors();
});

it('opens a chip menu from the right edge of the scrolled row without clipping it', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->script('document.querySelector(".fi-filter-bar-start").scrollLeft = 99999');

    $page->script('[...document.querySelectorAll("[data-filter-more]")].at(-1).click()');

    $inside = <<<'JS'
        (() => {
            const pop = document.querySelector("[data-pop][data-open=true][role=menu]");

            if (!pop?.checkVisibility()) {
                return false;
            }

            const box = pop.getBoundingClientRect();
            const chip = [...document.querySelectorAll("[data-filter-chip]")].at(-1).getBoundingClientRect();
            const corners = [[box.left + 4, box.top + 4], [box.right - 4, box.top + 4], [box.left + 4, box.bottom - 4], [box.right - 4, box.bottom - 4]];

            return corners.every(([x, y]) => pop.contains(document.elementFromPoint(x, y)))
                && Math.abs(box.right - chip.right) <= 1
                && Math.abs(box.top - chip.bottom - 6) <= 1;
        })()
    JS;

    $page->assertScript(filterBarWaitFor($inside), true)
        ->assertNoJavaScriptErrors();
});

it('opens a chip menu from a mouse click on a control under the fade', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->script('(() => { const strip = document.querySelector(".fi-filter-bar-start"); const more = document.querySelectorAll("[data-filter-more]")[3]; strip.scrollLeft = 0; strip.scrollLeft += more.getBoundingClientRect().right - (strip.getBoundingClientRect().right - 8); more.setAttribute("data-probe", ""); })()');

    $page->click('[data-probe]')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-pop][data-open=true][role=menu]")?.checkVisibility()'), true)
        ->assertNoJavaScriptErrors();
});

it('anchors only the popover that is open', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $anchored = 'document.querySelectorAll("[data-pop]").length';

    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->assertScript("{$anchored} === 0", true)
        ->script('document.querySelectorAll("[data-filter-more]")[2].click()');

    $page->assertScript(filterBarWaitFor("{$anchored} === 1 && [...document.querySelectorAll('[data-pop]')].every((pop) => pop._x_anchor)"), true)
        ->assertNoJavaScriptErrors();
});

it('titles the dialog opened from create new view and keeps the save as new view title from the save arrow', function (): void {
    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-save-current')
        ->assertSee('Create view')
        ->assertDontSee('Save as new view');
});

it('shows the title column icon in view settings like every other column', function (): void {
    ($this->open)()
        ->click('.fi-view-settings-trigger')
        ->assertScript('(() => { const rows = [...document.querySelectorAll("[data-view-settings] .fi-view-settings-row")]; return rows.length > 1 && rows.every((row) => row.querySelector(".fi-view-settings-label svg, .fi-view-settings-label .fi-icon, .fi-view-settings-label img")); })()', true);
});

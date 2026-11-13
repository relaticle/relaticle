<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\WorkspaceRole;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
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

it('sets the sort from the control and shows it in the column header, then flips and clears it', function (): void {
    $page = ($this->open)()
        ->click('[data-filter-sort]')
        ->assertScript(popVisible(), true)
        ->click('[data-sort-column][data-column="name"]');

    $page->assertScript(filterBarWaitFor('document.querySelector(".fi-ta-header-cell-name")?.getAttribute("aria-sort") === "ascending"'), true)
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").classList.contains("fi-set")'), true)
        ->assertSeeIn('[data-filter-sort]', 'Company')
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()', true)
        ->assertScript('document.activeElement.matches(`[data-sort-row][data-column="name"] [data-sort-field]`)', true)
        ->click('[data-sort-row][data-column="name"] [data-sort-direction]')
        ->assertScript(filterBarWaitFor('document.querySelector(".fi-ta-header-cell-name")?.getAttribute("aria-sort") === "descending"'), true)
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()', true)
        ->click('[data-sort-row][data-column="name"] [data-sort-remove]')
        ->assertScript(filterBarWaitFor('document.querySelector(".fi-ta-header-cell-name")?.getAttribute("aria-sort") === "none"'), true)
        ->assertScript('! document.querySelector("[data-filter-sort]").classList.contains("fi-set")', true)
        ->assertScript('[...document.querySelectorAll("[data-pop][data-open=true] [data-sort-column]")].every((item) => item.querySelector("svg path")?.getAttribute("d"))', true)
        ->assertNoJavaScriptErrors();
});

it('adds, searches, reorders and keeps several sorts', function (): void {
    $page = ($this->open)()
        ->click('[data-filter-sort]')
        ->assertScript('document.activeElement.matches("[data-sort-pop] input[data-filter-find]")', true)
        ->type('[data-sort-pop] input[data-filter-find]', 'compa')
        ->assertScript('[...document.querySelectorAll("[data-sort-pop] [data-sort-column]")].map((item) => item.dataset.column)', ['name'])
        ->keys('[data-sort-pop] input[data-filter-find]', 'Enter')
        ->assertScript(filterBarWaitFor('document.querySelectorAll("[data-sort-row]").length === 1'), true)
        ->click('[data-sort-add]')
        ->assertScript('[...document.querySelectorAll("[data-sort-pop] [data-sort-column]")].some((item) => item.dataset.column === "name")', false)
        ->keys('[data-sort-pop] input[data-filter-find]', 'Escape')
        ->assertScript('document.querySelectorAll("[data-sort-row]").length === 1', true)
        ->click('[data-sort-add]')
        ->click('[data-sort-pop] [data-sort-column][data-column="created_at"]');

    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort-more]")?.textContent.trim() === "+1"'), true)
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").getAttribute("aria-label") === "Sorted by Company, ascending, then 1 more"'), true)
        ->keys('[data-sort-row][data-column="created_at"] [data-sort-grip]', 'ArrowUp')
        ->assertScript(filterBarWaitFor('[...document.querySelectorAll("[data-sort-row]")].map((row) => row.dataset.column).join() === "created_at,name"'), true)
        ->assertScript('document.activeElement.matches(`[data-sort-row][data-column="created_at"] [data-sort-grip]`)', true)
        ->assertScript(filterBarWaitFor('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableThenSorts === "name:asc"'), true)
        ->refresh()
        ->assertPresent('[data-filter-sort].fi-set')
        ->assertSeeIn('[data-filter-sort]', 'Sorted by Creation Date')
        ->click('[data-filter-sort]')
        ->assertScript('[...document.querySelectorAll("[data-sort-row]")].map((row) => row.dataset.column).join()', 'created_at,name')
        ->assertNoJavaScriptErrors();
});

it('focuses the field search every time the filter picker opens', function (): void {
    $searchFocused = 'document.activeElement.matches("[data-pop][data-open=true] input[data-filter-find]")';
    $page = ($this->open)()
        ->click('[data-filter-add]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->click('[data-pop][data-open=true] [data-field="name"]')
        ->assertScript(filterBarWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true)
        ->click('[data-filter-add]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->keys('[data-pop][data-open=true] input[data-filter-find]', 'Escape')
        ->click('[data-filter-add]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->assertNoJavaScriptErrors();
});

it('focuses the search every time the sort picker opens from the editor', function (): void {
    $page = ($this->open)()
        ->click('[data-filter-sort]')
        ->click('[data-sort-pop] [data-sort-column][data-column="name"]');
    $searchFocused = 'document.activeElement.matches("[data-sort-pop] input[data-filter-find]")';

    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-sort-row]").length === 1'), true)
        ->click('[data-sort-add]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->click('[data-sort-pop] [data-sort-column][data-column="created_at"]');

    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-sort-row]").length === 2'), true)
        ->click('[data-sort-add]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->keys('[data-sort-pop] input[data-filter-find]', 'Escape')
        ->click('[data-sort-row][data-column="name"] [data-sort-field]')
        ->assertScript(filterBarWaitFor($searchFocused), true)
        ->assertNoJavaScriptErrors();
});

it('drags sort rows into a new order and keeps the list and the rows in step', function (): void {
    $page = ($this->open)();
    $page->script('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.setFilterBarSorts([{ column: "name", direction: "asc" }, { column: "created_at", direction: "desc" }, { column: "updated_at", direction: "asc" }])');
    $rows = '[...document.querySelectorAll("[data-sort-row]")].map((row) => row.dataset.column).join()';
    $stored = '(() => { const wire = Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire; return [wire.tableSort, wire.tableThenSorts].join(","); })()';

    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort-more]")?.textContent.trim() === "+2"'), true)
        ->click('[data-filter-sort]')
        ->drag('[data-sort-row][data-column="updated_at"] [data-sort-grip]', '[data-sort-row][data-column="name"] [data-sort-grip]');

    $page->assertScript(filterBarWaitFor($stored.' === "updated_at:asc,name:asc,created_at:desc"'), true)
        ->assertScript($rows, 'updated_at,name,created_at')
        ->assertScript('document.querySelectorAll("[data-sort-row]").length', 3)
        ->drag('[data-sort-row][data-column="updated_at"] [data-sort-grip]', '[data-sort-row][data-column="created_at"] [data-sort-grip]');

    $page->assertScript(filterBarWaitFor($stored.' === "name:asc,created_at:desc,updated_at:asc"'), true)
        ->assertScript($rows, 'name,created_at,updated_at')
        ->assertScript('document.querySelectorAll("[data-sort-row]").length', 3)
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()', true)
        ->assertNoJavaScriptErrors();
});

it('reads Sort until a column is set and then Sorted by the column in two tones', function (): void {
    $page = ($this->open)();

    $page->assertSeeIn('[data-filter-sort]', 'Sort')
        ->assertDontSeeIn('[data-filter-sort]', 'Sorted by')
        ->click('[data-filter-sort]')
        ->click('[data-sort-column][data-column="name"]')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").classList.contains("fi-set")'), true)
        ->assertSeeIn('[data-filter-sort]', 'Sorted by Company')
        ->assertScript('(() => { const [muted, column] = ["fi-filter-muted", "fi-filter-sorted-column"].map((name) => getComputedStyle(document.querySelector("[data-filter-sort] ." + name)).color); return muted !== column; })()', true)
        ->assertScript('document.querySelector("[data-filter-sort]").getAttribute("aria-label").includes("Company")', true)
        ->click('[data-sort-row][data-column="name"] [data-sort-direction]')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").getAttribute("aria-label").includes("descending")'), true)
        ->assertNoJavaScriptErrors();
});

it('keeps the sort control icon-only on a phone with the column in its name', function (): void {
    $page = ($this->open)(390, 844);

    $page->script('Livewire.all().find((component) => /List(Companies|People)/.test(component.name)).$wire.setFilterBarSorts([{ column: "name", direction: "asc" }])');
    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").classList.contains("fi-set")'), true)
        ->assertScript('[...document.querySelectorAll("[data-filter-sort] > span")].every((span) => !span.checkVisibility())', true)
        ->assertScript('document.querySelector("[data-filter-sort]").getAttribute("aria-label").includes("Company")', true)
        ->assertNoJavaScriptErrors();
});

it('draws no Filament sort row under the filter row on a phone', function (): void {
    $page = ($this->open)(390, 844);

    $page->assertScript('document.querySelectorAll(".fi-ta-table-stacked-sorting").length > 0', true)
        ->assertScript('[...document.querySelectorAll(".fi-ta-table-stacked-sorting")].every((row) => ! row.checkVisibility())', true)
        ->assertNoJavaScriptErrors();
});

it('groups tasks from the group control and leaves Filament no toolbar row at rest', function (): void {
    $page = openOn($this->user, $this->workspace, 'tasks');

    $page->assertPresent('[data-filter-group]')
        ->assertMissing('.fi-ta-header-toolbar')
        ->click('[data-filter-group]')
        ->assertScript(popVisible(), true)
        ->click('[data-group="priority_group"]')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-group]").classList.contains("fi-set") && document.querySelector("[data-filter-group]").textContent.includes("Priority")'), true)
        ->assertScript("Livewire.all().find((component) => component.name.includes('ManageTasks')).\$wire.tableGrouping === 'priority_group:asc'", true)
        ->assertMissing('.fi-ta-grouping-settings')
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()', true)
        ->click('[data-group-clear]')
        ->assertScript("Livewire.all().find((component) => component.name.includes('ManageTasks')).\$wire.tableGrouping === null", true)
        ->assertNoJavaScriptErrors();
});

it('shows the group direction arrow on the group control and swaps it when the direction flips', function (): void {
    $visibleIcon = 'JSON.stringify([...document.querySelectorAll("[data-filter-group] svg")].filter((icon) => icon.checkVisibility()).map((icon) => icon.innerHTML))';

    $page = openOn($this->user, $this->workspace, 'tasks');
    $page->script("window.probeIcons = [JSON.parse({$visibleIcon})]");
    $page->click('[data-filter-group]')
        ->click('[data-group="priority_group"]')
        ->assertScript(filterBarWaitFor("JSON.parse({$visibleIcon}).length === 1 && JSON.parse({$visibleIcon})[0] !== window.probeIcons[0][0]"), true);
    $page->script("window.probeIcons.push(JSON.parse({$visibleIcon})[0])");
    $page->click('[data-group-flip]')
        ->assertScript(filterBarWaitFor("JSON.parse({$visibleIcon}).length === 1 && JSON.parse({$visibleIcon})[0] !== window.probeIcons[1]"), true)
        ->assertScript(filterBarWaitFor("JSON.parse({$visibleIcon})[0] !== window.probeIcons[0][0]"), true)
        ->assertNoJavaScriptErrors();
});

it('offers no group control on a list that groups nothing', function (): void {
    ($this->open)()
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]")?.checkVisibility()'), true)
        ->assertMissing('[data-filter-group]')
        ->assertNoJavaScriptErrors();
});

it('draws no empty header row under the filter row on a phone for a viewer', function (): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    openOn($viewer, $this->workspace, 'companies', 390)
        ->assertScript(filterBarWaitFor('document.querySelectorAll(".fi-ta-table-stacked-header-row").length > 0'), true)
        ->assertScript('[...document.querySelectorAll(".fi-ta-table-stacked-header-row")].filter((row) => row.checkVisibility()).length === 0', true)
        ->assertNoJavaScriptErrors();
});

it('keeps the select row under the filter row on a phone for an owner', function (): void {
    $visibleRows = '[...document.querySelectorAll(".fi-ta-table-stacked-header-row")].filter((row) => row.checkVisibility())';

    openOn($this->user, $this->workspace, 'companies', 390)
        ->assertScript(filterBarWaitFor("{$visibleRows}.length === 1 && {$visibleRows}[0].querySelector('.fi-ta-page-checkbox')"), true)
        ->assertNoJavaScriptErrors();
});

it('changes the field of an unfinished chip in place and opens its conditions', function (): void {
    $page = ($this->open)();

    addChip($page, 'name', 'contains')
        ->click('[data-filter-segment="field"]')
        ->assertAttribute('[data-filter-segment="field"]', 'aria-expanded', 'true')
        ->click('[data-pop-item][data-field="creation_source"]')
        ->assertPresent('[data-filter-chip][data-field="creation_source"].fi-pending')
        ->assertScript('document.querySelectorAll("[data-filter-chip]").length === 1', true)
        ->assertSeeIn('[data-filter-chip]', 'Select condition')
        ->assertPresent('[data-pop][data-open=true] [data-condition="is_any_of"]');
    expect($page->script(popVisible()))->toBeTrue();

    $page->click('[data-pop][data-open=true] [data-condition="is_any_of"]')
        ->click('[data-filter-segment="field"]')
        ->click('[data-pop-item][data-field="creation_source"]')
        ->assertPresent('[data-filter-chip][data-field="creation_source"]')
        ->assertNoJavaScriptErrors();
});

it('widens the list when a complete chip of a saved view changes field', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Acme only',
        'state' => new TableStateData(filters: ['conditions' => ['match' => 'all', 'rules' => [['field' => 'name', 'condition' => 'contains', 'value' => 'acme']]]]),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Acme only');
    expect(companyRows($page, 1))->toBeTrue();
    $page->click('[data-filter-segment="field"]')
        ->click('[data-pop-item][data-field="creation_source"]');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertPresent('[data-filter-chip][data-field="creation_source"].fi-pending')
        ->assertScript(filterRowSaveControlCount(), 0)
        ->assertNoJavaScriptErrors();
});

it('opens the field list of a chip from the keyboard', function (): void {
    $page = ($this->open)();

    addChip($page, 'name', 'contains')->keys(valueInput(), 'Escape');
    $page->script('document.querySelector("[data-filter-segment=field]").focus()');
    $page->assertAttribute('[data-filter-segment="field"]', 'aria-haspopup', 'listbox')
        ->assertAttribute('[data-filter-segment="field"]', 'aria-label', 'Change field for Company contains')
        ->keys('[data-filter-segment="field"]', 'Enter')
        ->assertAttribute('[data-filter-segment="field"]', 'aria-expanded', 'true')
        ->assertScript(chipFocus('[data-pop][data-open=true] [data-filter-find]'), true)
        ->keys('[data-pop][data-open=true] [data-filter-find]', 'Escape')
        ->assertScript(chipFocus('[data-filter-segment="field"]'), true)
        ->assertNoJavaScriptErrors();
});

it('shows the marks of the picked members and companies in the chips after a reload', function (): void {
    $acme = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->where('name', 'Acme Robotics')->value('id');
    $page = openOn($this->user, $this->workspace, 'people');

    addChip($page, 'company', 'is_any_of')->click(optionButton((string) $acme))->keys(searchInput(), 'Escape');
    addChip($page, 'creator', 'is_any_of')->click(optionButton('@me'))->keys(searchInput(), 'Escape');
    $page->assertScript(filterBarWaitFor('JSON.parse(document.querySelector("[data-filter-bar]").dataset.state).rules.length === 2'), true)
        ->navigate("/app/{$this->workspace->slug}/people")
        ->assertPresent('[data-filter-chip][data-field="company"] .fi-filter-value .fi-filter-mark.fi-square svg')
        ->assertPresent('[data-filter-chip][data-field="creator"] .fi-filter-value .fi-filter-mark img[src]')
        ->assertScript('(() => { const chip = document.querySelector("[data-filter-chip][data-field=creator]"); return chip.getBoundingClientRect().height <= 30 && chip.textContent.includes("(me)"); })()', true)
        ->assertNoJavaScriptErrors();
});

it('anchors the condition, value and more popovers to the segment that opened them', function (string $segment, string $edge): void {
    $page = ($this->open)();
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: 'a rather long value to widen the chip' }])");
    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true);
    $page->script("document.querySelector('[data-filter-chip] {$segment}').click()");

    $aligned = <<<JS
        (() => {
            const pop = document.querySelector('[data-pop][data-open=true]');
            const trigger = document.querySelector('[data-filter-chip] {$segment}');

            if (!pop?.checkVisibility()) {
                return false;
            }

            const box = pop.getBoundingClientRect();
            const anchor = trigger.getBoundingClientRect();

            return Math.abs(box.{$edge} - anchor.{$edge}) <= 1 && Math.abs(box.top - anchor.bottom - 6) <= 1;
        })()
    JS;

    $page->assertScript(filterBarWaitFor($aligned), true)->assertNoJavaScriptErrors();
})->with([
    'condition' => ['[data-filter-segment="condition"]', 'left'],
    'value' => ['[data-filter-segment="value"]', 'left'],
    'more' => ['[data-filter-more]', 'right'],
]);

it('shifts a value popover left instead of clipping it at the right edge', function (): void {
    $page = ($this->open)(1100);
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->script('(() => { const strip = document.querySelector(".fi-filter-bar-start"); strip.scrollLeft = 99999; document.querySelectorAll("[data-filter-chip] [data-filter-segment=value]")[7].click(); })()');

    $inside = 'document.querySelector("[data-pop][data-open=true]")?.checkVisibility() && document.querySelector("[data-pop][data-open=true]").getBoundingClientRect().right <= window.innerWidth && document.querySelector("[data-pop][data-open=true]").getBoundingClientRect().left >= 0';

    $page->assertScript(filterBarWaitFor($inside), true)->assertNoJavaScriptErrors();
});

function peopleCompanyChip(AwaitableWebpage $page, array $companyIds): AwaitableWebpage
{
    addChip($page, 'company', 'is_any_of');

    foreach ($companyIds as $id) {
        $page->click(optionButton((string) $id));
    }

    return $page;
}

function companyId(Workspace $workspace, string $name): string
{
    return (string) Company::query()->withoutGlobalScopes()->where('workspace_id', $workspace->getKey())->where('name', $name)->value('id');
}

it('swaps a company logo that fails to load for the company icon in the list and in the chip', function (): void {
    $company = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->where('name', 'Acme Robotics')->first();
    $media = $company->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    unlink($media->getPath());
    $page = openOn($this->user, $this->workspace, 'people');
    $mark = fn (string $scope): string => "document.querySelector('{$scope} .fi-filter-mark')";
    $iconShown = fn (string $scope): string => "{$mark($scope)}?.querySelector('svg')?.checkVisibility() === true && !{$mark($scope)}.querySelector('img')?.checkVisibility()";

    addChip($page, 'company', 'is_any_of')->assertPresent(optionButton((string) $company->getKey()).' [data-filter-mark] img');
    $page->assertScript(filterBarWaitFor($iconShown(optionButton((string) $company->getKey()))), true)
        ->click(optionButton((string) $company->getKey()))
        ->assertScript(filterBarWaitFor($iconShown('[data-filter-chip][data-field="company"] .fi-filter-value')), true)
        ->assertNoJavaScriptErrors();
});

it('shows the first long name truncated and a whole plus count instead of squeezing the second name', function (): void {
    $long = 'VER1111 Northwestern Regional Renewable Energy Holdings';
    foreach ([$long, 'Airbnb'] as $name) {
        Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]);
    }
    $page = openOn($this->user, $this->workspace, 'people');

    peopleCompanyChip($page, [companyId($this->workspace, $long), companyId($this->workspace, 'Airbnb')])->keys(searchInput(), 'Escape');

    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-filter-chip] .fi-filter-value").length === 1'), true)
        ->assertScript('(() => { const label = document.querySelector("[data-filter-chip] .fi-filter-value-label"); return label.textContent.trim() === '.json_encode($long).' && label.scrollWidth > label.clientWidth; })()', true)
        ->assertScript('(() => { const more = document.querySelector("[data-filter-chip] .fi-filter-more-values"); const segment = document.querySelector("[data-filter-chip] [data-filter-segment=value]").getBoundingClientRect(); const box = more.getBoundingClientRect(); return more.textContent.trim() === "+1" && more.scrollWidth <= more.clientWidth && box.width > 0 && box.right <= segment.right; })()', true)
        ->assertNoJavaScriptErrors();
});

it('shows two whole short names with their marks, a visible comma and a plus count', function (): void {
    $names = ['Airbnb', 'Stripe', 'Figma', 'Heh'];
    foreach ($names as $name) {
        Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]);
    }
    $page = openOn($this->user, $this->workspace, 'people');

    peopleCompanyChip($page, array_map(fn (string $name): string => companyId($this->workspace, $name), $names))->keys(searchInput(), 'Escape');

    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-filter-chip] .fi-filter-value").length === 2'), true)
        ->assertScript('JSON.stringify([...document.querySelectorAll("[data-filter-chip] .fi-filter-value-label")].map((label) => label.textContent.trim()))', '["Airbnb","Stripe"]')
        ->assertScript('[...document.querySelectorAll("[data-filter-chip] .fi-filter-value")].every((value) => value.querySelector(".fi-filter-mark") && value.querySelector(".fi-filter-value-label").scrollWidth <= value.querySelector(".fi-filter-value-label").clientWidth)', true)
        ->assertScript('(() => { const [first, second] = document.querySelectorAll("[data-filter-chip] .fi-filter-value"); const comma = first.lastElementChild.getBoundingClientRect(); return first.lastElementChild.textContent === "," && comma.width > 0 && comma.right <= second.getBoundingClientRect().left; })()', true)
        ->assertSeeIn('[data-filter-chip] .fi-filter-more-values', '+2')
        ->assertNoJavaScriptErrors();
});

it('marks the current field in the field list of a chip', function (): void {
    $page = ($this->open)();

    addChip($page, 'name', 'contains')->keys(valueInput(), 'Escape');
    $page->click('[data-filter-segment="field"]')
        ->assertScript('[...document.querySelectorAll("[data-pop][data-open=true] [data-field]")].filter((item) => item.getAttribute("aria-selected") === "true").map((item) => item.dataset.field).join() === "name"', true)
        ->assertScript('document.querySelector("[data-pop][data-open=true] [data-field=name] .fi-menu-check").checkVisibility()', true)
        ->assertNoJavaScriptErrors();
});

it('draws the unsorted sort control with a two-way arrow at every width', function (int $width): void {
    $page = ($this->open)($width);
    $paths = fn (string $html): array => (preg_match_all('/ d="([^"]+)"/', $html, $matches) ? $matches[1] : []);
    $shown = $page->script('[...document.querySelectorAll("[data-filter-sort] svg:not(.fi-filter-sorted-icon)")].filter((svg) => svg.checkVisibility()).map((svg) => [...svg.querySelectorAll("path")].map((path) => path.getAttribute("d")))[0]');

    expect($shown)->toBe($paths(svg('lucide-arrow-up-down')->toHtml()));
    $page->assertNoJavaScriptErrors();
})->with([390, 1440]);

it('turns the sort control icon to the sort direction', function (string $direction, string $icon): void {
    $page = ($this->open)();
    $paths = fn (string $html): array => (preg_match_all('/ d="([^"]+)"/', $html, $matches) ? $matches[1] : []);

    $page->script(sprintf('Livewire.all().find((component) => /List(Companies|People)/.test(component.name)).$wire.setFilterBarSorts([{ column: "name", direction: "%s" }])', $direction));
    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-sort]").classList.contains("fi-set")'), true);

    expect($page->script('[...document.querySelectorAll("[data-filter-sort] svg")].filter((svg) => svg.checkVisibility()).map((svg) => [...svg.querySelectorAll("path")].map((path) => path.getAttribute("d")))'))
        ->toBe([$paths(svg($icon)->toHtml())]);
    $page->assertNoJavaScriptErrors();
})->with(['ascending' => ['asc', 'lucide-arrow-up-narrow-wide'], 'descending' => ['desc', 'lucide-arrow-down-wide-narrow']]);

it('lists the viewer once whichever way the rule stores them, and unchecks both', function (): void {
    $page = openOn($this->user, $this->workspace, 'opportunities?view=all');
    $viewer = (string) $this->user->getKey();
    $page->script("Livewire.all().find((component) => component.name.includes('ListOpportunities')).\$wire.\$call('setFilterRules', [{ field: 'creator', condition: 'is_any_of', value: ['@me', '{$viewer}'] }])");
    $page->assertScript(filterBarWaitFor('document.querySelectorAll("[data-filter-chip]").length === 1'), true)
        ->assertSeeIn('[data-filter-chip] [data-filter-segment="value"]', "{$this->user->name} (me)")
        ->assertScript('document.querySelector("[data-filter-chip] [data-filter-segment=value]").textContent.split("(me)").length === 2', true)
        ->click('[data-filter-chip] [data-filter-segment="value"]')
        ->assertScript('[...document.querySelectorAll("[data-pop][data-open=true] [data-option]")].length === 1 && document.querySelector("[data-pop][data-open=true] [data-option=\\"@me\\"]").getAttribute("aria-selected") === "true"', true)
        ->click(optionButton('@me'))
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-bar]").dataset.state && JSON.parse(document.querySelector("[data-filter-bar]").dataset.state).rules.length === 0'), true)
        ->assertNoJavaScriptErrors();
});

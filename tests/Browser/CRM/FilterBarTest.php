<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Helpers\WorkspaceCustomField;

mutates(HasFilterBar::class);

function companyRows(AwaitableWebpage $page, int $count): bool
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80; attempt++) { if (document.querySelectorAll('.fi-ta-row').length === {$count}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()");
}

function addChip(AwaitableWebpage $page, string $field, string $condition): AwaitableWebpage
{
    return $page->click('[data-filter-add]')
        ->click("[data-pop-item][data-field=\"{$field}\"]")
        ->click("[data-pop]:not([style*=\"display: none\"]) [data-condition=\"{$condition}\"]");
}

function setRules(AwaitableWebpage $page, int $count): void
{
    $page->script("Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', Array.from({ length: {$count} }, (_, index) => ({ field: 'name', condition: 'contains', value: 'chip' + index })))");
}

function valueInput(): string
{
    return '[data-pop]:not([style*="display: none"]) [data-filter-value]';
}

function customFieldOn(User $user, string $entity, string $code, string $type): void
{
    resolve(CreateCustomField::class)->execute($user, ['entity_type' => $entity, 'name' => ucfirst($code), 'code' => $code, 'type' => $type]);
}

function setCustomValue(User $user, object $record, string $entity, string $code, mixed $value): void
{
    $workspace = $user->currentWorkspace;

    resolve(CurrentWorkspace::class)->within($workspace, fn () => $record->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), $entity, $code), $value));
}

function selectFieldOn(User $user, string $entity, string $code, array $options): void
{
    resolve(CreateCustomField::class)->execute($user, [
        'entity_type' => $entity,
        'name' => ucfirst($code),
        'code' => $code,
        'type' => 'select',
        'options' => array_map(static fn (string $name): array => ['name' => $name], $options),
    ]);
}

function optionIdOf(User $user, string $entity, string $code, string $name): string
{
    return (string) WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), $entity, $code)->options->firstWhere('name', $name)->getKey();
}

function optionButton(string $id): string
{
    return "[data-pop]:not([style*=\"display: none\"]) [data-option=\"{$id}\"]";
}

function searchInput(): string
{
    return '[data-pop]:not([style*="display: none"]) [data-filter-search]';
}

function chipFocus(string $selector): string
{
    return "document.activeElement === document.querySelector('{$selector}') && document.activeElement !== document.body";
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    foreach (['Acme Robotics', 'Globex', 'Initech'] as $name) {
        Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]);
    }

    $this->open = fn (int $width = 1440, int $height = 900): AwaitableWebpage => loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize($width, $height)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertPresent('[data-filter-add]');
});

it('draws a dashed sort and a dashed filter button when no filter is set', function (): void {
    ($this->open)()
        ->assertSeeIn('[data-filter-bar]', 'Sort')
        ->assertSeeIn('[data-filter-add]', 'Filter')
        ->assertPresent('[data-filter-sort][disabled]')
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
        ->assertSeeIn('[data-filter-chip]', 'Select value')
        ->assertDontSee('Discard changes');
    expect(companyRows($page, 3))->toBeTrue();
});

it('shows the save controls for a complete chip on a saved view and keeps the chip after save and reload', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'created_at:asc'),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads')
        ->assertDontSee('Discard changes');

    addChip($page, 'name', 'contains')->type(valueInput(), 'glo');
    $page->assertSee('Discard changes')->assertSee('Save');
    expect(companyRows($page, 1))->toBeTrue();

    $page->click('[data-view-save-action="save"]')
        ->assertDontSee('Discard changes')
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertSeeIn('[data-filter-chip]', 'glo')
        ->assertDontSee('Discard changes');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('discards a chip with the view changes', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Hot leads']);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');

    addChip($page, 'name', 'contains')->type(valueInput(), 'glo');
    expect(companyRows($page, 1))->toBeTrue();

    $page->click('[data-view-save-action="discard"]')
        ->assertMissing('[data-filter-chip]');
    expect(companyRows($page, 3))->toBeTrue();
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

it('scrolls a new chip and its list into view', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true);

    addChip($page, 'name', 'contains');

    $page->assertScript('(() => { const strip = document.querySelector(".fi-filter-bar-start").getBoundingClientRect(); const chip = [...document.querySelectorAll("[data-filter-chip]")].at(-1).getBoundingClientRect(); return chip.left >= strip.left && chip.right <= strip.right; })()', true)
        ->assertScript('(() => { const pop = document.querySelector("[data-pop][data-open=true]").getBoundingClientRect(); return pop.left >= 0 && pop.right <= innerWidth && pop.bottom <= innerHeight; })()', true)
        ->assertNoJavaScriptErrors();
});

it('opens a chip menu from the right edge of the scrolled row without clipping it', function (): void {
    $page = ($this->open)();
    setRules($page, 8);
    $page->assertScript('document.querySelectorAll("[data-filter-chip]").length === 8', true)
        ->script('document.querySelector(".fi-filter-bar-start").scrollLeft = 99999');

    $page->script('[...document.querySelectorAll("[data-filter-more]")].at(-1).click()');

    $page->assertScript('(async () => { await new Promise((resolve) => setTimeout(resolve, 500)); const pop = document.querySelector("[data-pop][data-open=true][role=menu]"); const box = pop.getBoundingClientRect(); const item = pop.querySelector("[data-pop-item]").getBoundingClientRect(); const hit = document.elementFromPoint(item.left + item.width / 2, item.top + item.height / 2); const chip = [...document.querySelectorAll("[data-filter-chip]")].at(-1).getBoundingClientRect(); return box.left >= 0 && box.right <= innerWidth && box.top >= chip.bottom && pop.offsetParent === null && pop.contains(hit); })()', true)
        ->assertNoJavaScriptErrors();
});

it('titles the dialog opened from create new view and keeps the save as new view title from the save arrow', function (): void {
    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-save-current')
        ->assertSee('Create view')
        ->assertSee('Starts from the columns, sort and filters on screen now.')
        ->assertDontSee('Save as new view');
});

it('shows the title column icon in view settings like every other column', function (): void {
    ($this->open)()
        ->click('.fi-view-settings-trigger')
        ->assertScript('(() => { const rows = [...document.querySelectorAll("[data-view-settings] .fi-view-settings-row")]; return rows.length > 1 && rows.every((row) => row.querySelector(".fi-view-settings-label svg, .fi-view-settings-label .fi-icon, .fi-view-settings-label img")); })()', true);
});

it('narrows opportunities with a number chip', function (): void {
    customFieldOn($this->user, 'opportunity', 'budget', 'number');
    foreach (['Small' => 100, 'Mid' => 1000, 'Big' => 5000] as $name => $budget) {
        setCustomValue($this->user, Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]), 'opportunity', 'budget', $budget);
    }

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPresent('[data-filter-add]');
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'custom_fields.budget', 'greater_than')
        ->assertAttribute(valueInput(), 'type', 'number')
        ->assertAttribute(valueInput(), 'placeholder', '0')
        ->type(valueInput(), '1000');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Big')
        ->assertSeeIn('[data-filter-chip]', '1000')
        ->assertNoJavaScriptErrors();
});

it('narrows companies with a checkbox chip that takes no value', function (): void {
    customFieldOn($this->user, 'company', 'vip', 'checkbox');
    $marked = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Marked']);
    setCustomValue($this->user, $marked, 'company', 'vip', true);

    $page = ($this->open)();
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'custom_fields.vip', 'is_checked')
        ->assertMissing('[data-filter-segment="value"]')
        ->assertScript('document.querySelector("[data-filter-chip]").dataset.complete === "true"', true);
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Marked')->assertNoJavaScriptErrors();
});

it('completes a condition that takes no value at once and narrows the list', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Robotics Two']);

    $page = ($this->open)();
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'name', 'is_not_empty')
        ->assertMissing('[data-filter-segment="value"]')
        ->assertScript('document.querySelector("[data-filter-chip]").dataset.complete === "true"', true);
    expect(companyRows($page, 4))->toBeTrue();

    $page->click('[data-filter-segment="condition"]')
        ->click('[data-condition="is_empty"]');
    expect(companyRows($page, 0))->toBeTrue();
    $page->assertSee('No companies match this view');
});

it('does not let a stored chip the picker does not offer change its condition', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Made today',
        'state' => new TableStateData(filters: ['conditions' => ['match' => 'all', 'rules' => [['field' => 'created_at', 'condition' => 'is', 'value' => '@today']]]]),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Made today')
        ->assertSeeIn('[data-filter-chip]', 'today');

    $page->assertScript('document.querySelector("[data-filter-chip] [data-filter-segment=condition]").tagName === "SPAN"', true)
        ->assertScript('document.querySelector("[data-filter-chip] [data-filter-segment=value]").tagName === "SPAN"', true);

    $shapeCrossing = <<<'JS'
        (() => {
            const bar = Alpine.$data(document.querySelector('[data-filter-bar]'));
            const chip = bar.chips[0];
            bar.pickCondition(chip, bar.fieldOf(chip).conditions.find((condition) => condition.value === 'in_the_past'));

            return chip.value;
        })()
    JS;

    expect($page->script($shapeCrossing))->toBeNull();
});

it('drops an unfinished chip when another view with the same rules is opened', function (): void {
    foreach (['Oldest first' => 'created_at:asc', 'Newest first' => 'created_at:desc'] as $name => $sort) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => $name, 'state' => new TableStateData(sort: $sort)]);
    }

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Oldest first');
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'name', 'contains')
        ->assertPresent('[data-filter-chip].fi-pending')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Newest first')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Newest first')
        ->assertMissing('[data-filter-chip]')
        ->assertNoJavaScriptErrors();
});

it('names every popover and every chip menu button after its chip', function (): void {
    $page = ($this->open)();
    addChip($page, 'name', 'contains')->type(valueInput(), 'acme');
    $page->keys(valueInput(), 'Enter');
    expect(companyRows($page, 1))->toBeTrue();

    $names = <<<'JS'
        (() => {
            const chip = document.querySelector('[data-filter-chip]');

            return [
                chip.querySelector('[role=listbox]').getAttribute('aria-label'),
                chip.querySelector('[role=dialog]').getAttribute('aria-label'),
                chip.querySelector('[role=menu]').getAttribute('aria-label'),
                chip.querySelector('[data-filter-more]').getAttribute('aria-label'),
            ];
        })()
    JS;

    expect($page->script($names))->toBe([
        'Conditions for Company',
        'Value for Company contains acme',
        'Options for Company contains acme',
        'More options for Company contains acme',
    ]);
});

it('narrows opportunities with a pipeline checklist and summarises the picks', function (): void {
    selectFieldOn($this->user, 'opportunity', 'pipeline', ['Won', 'Lost', 'Open', 'Stalled']);

    foreach (['Won', 'Lost', 'Open', 'Stalled'] as $stage) {
        setCustomValue($this->user, Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => "Deal {$stage}"]), 'opportunity', 'pipeline', optionIdOf($this->user, 'opportunity', 'pipeline', $stage));
    }

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPresent('[data-filter-add]');
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'custom_fields.pipeline', 'is_none_of')
        ->assertMissing('[data-filter-search]')
        ->click(optionButton(optionIdOf($this->user, 'opportunity', 'pipeline', 'Won')));
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertDontSee('Deal Won')->assertSeeIn('[data-filter-segment="value"]', 'Won');

    $page->click(optionButton(optionIdOf($this->user, 'opportunity', 'pipeline', 'Lost')))
        ->click(optionButton(optionIdOf($this->user, 'opportunity', 'pipeline', 'Open')))
        ->assertSeeIn('[data-filter-segment="value"]', 'Won, Lost +1')
        ->click(optionButton(optionIdOf($this->user, 'opportunity', 'pipeline', 'Stalled')))
        ->assertSeeIn('[data-filter-segment="value"]', 'Won, Lost +2')
        ->assertNoJavaScriptErrors();
    expect(companyRows($page, 0))->toBeTrue();
});

it('lists a long choice with a search field and keeps the checked option on top while searching', function (): void {
    $names = array_map(fn (int $number): string => "Tier {$number}", range(1, 10));
    selectFieldOn($this->user, 'company', 'tier', $names);
    $page = ($this->open)();

    addChip($page, 'custom_fields.tier', 'is_any_of')
        ->assertPresent(searchInput())
        ->click(optionButton(optionIdOf($this->user, 'company', 'tier', 'Tier 7')))
        ->type(searchInput(), 'Tier 2');

    $order = <<<'JS'
        [...document.querySelectorAll('[data-pop][data-open="true"] [data-option]')].map((option) => option.textContent.trim())
    JS;

    expect($page->script($order))->toBe(['Tier 7', 'Tier 2']);
    $page->assertNoJavaScriptErrors();
});

it('narrows opportunities by creator with Me pinned first and a teammate after it', function (): void {
    $teammate = User::factory()->create(['name' => 'Priya Shah']);
    $this->workspace->users()->attach($teammate, ['role' => 'member']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->count(2)->create(['creator_id' => $this->user->getKey()]);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Priya deal', 'creator_id' => $teammate->getKey()]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPresent('[data-filter-add]');
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'creator', 'is_any_of')
        ->assertScript('document.querySelector("[data-pop][data-open=true] [data-option]").dataset.option === "@me"', true)
        ->click(optionButton('@me'));
    expect(companyRows($page, 2))->toBeTrue();
    $page->assertDontSee('Priya deal')
        ->click(optionButton((string) $teammate->getKey()))
        ->assertSeeIn('[data-filter-segment="value"]', 'Me, Priya Shah');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('searches the workspace as the member types a company beyond the first page and keeps the pick listed', function (): void {
    foreach (range(1, 22) as $number) {
        Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => sprintf('Filler %02d', $number)]);
    }

    $companies = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->pluck('id', 'name');
    foreach (['Acme Robotics', 'Globex', 'Initech'] as $name) {
        People::factory()->recycle([$this->user, $this->workspace])->create(['name' => "Person of {$name}", 'company_id' => $companies[$name]]);
    }

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/people")
        ->assertPresent('[data-filter-add]');
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'company', 'is_any_of')
        ->assertPresent(optionButton((string) $companies['Acme Robotics']))
        ->assertMissing(optionButton((string) $companies['Globex']))
        ->type(searchInput(), 'glo')
        ->assertPresent(optionButton((string) $companies['Globex']))
        ->assertMissing(optionButton((string) $companies['Acme Robotics']))
        ->click(optionButton((string) $companies['Globex']));
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Person of Globex')->assertSeeIn('[data-filter-segment="value"]', 'Globex');

    $page->type(searchInput(), 'zzqx')
        ->assertPresent(optionButton((string) $companies['Globex']).'[aria-selected="true"]')
        ->assertNoJavaScriptErrors();
});

it('never shows one chip lookup results in another chip list', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/opportunities")
        ->assertPresent('[data-filter-add]');

    addChip($page, 'creator', 'is_any_of')
        ->click(optionButton('@me'))
        ->keys(searchInput(), 'Escape');
    addChip($page, 'company', 'is_any_of')
        ->type(searchInput(), 'glo')
        ->click('[data-field="creator"] [data-filter-segment="value"]')
        ->assertPresent(optionButton('@me'));

    $options = <<<'JS'
        new Promise((resolve) => setTimeout(() => resolve([...document.querySelectorAll('[data-pop][data-open=true] [data-option]')].map((option) => option.textContent.trim())), 1200))
    JS;

    expect($page->script($options))->not->toContain('Globex')->toContain('Me');
    $page->assertNoJavaScriptErrors();
});

it('works the checklist with the keyboard alone', function (): void {
    selectFieldOn($this->user, 'company', 'tier', array_map(fn (int $number): string => "Tier {$number}", range(1, 10)));
    $first = optionIdOf($this->user, 'company', 'tier', 'Tier 1');
    $second = optionIdOf($this->user, 'company', 'tier', 'Tier 2');
    $page = ($this->open)();

    addChip($page, 'custom_fields.tier', 'is_any_of')
        ->assertScript(chipFocus('[data-filter-search]'), true)
        ->keys(searchInput(), 'ArrowDown')
        ->assertScript(chipFocus('[data-option="'.$first.'"]'), true)
        ->keys('[data-option="'.$first.'"]', 'Space')
        ->assertPresent(optionButton($first).'[aria-selected="true"]')
        ->keys('[data-option="'.$first.'"]', 'ArrowDown')
        ->assertScript(chipFocus('[data-option="'.$second.'"]'), true)
        ->keys('[data-option="'.$second.'"]', 'Enter')
        ->assertPresent(optionButton($second).'[aria-selected="true"]')
        ->assertSeeIn('[data-filter-segment="value"]', 'Tier 1, Tier 2')
        ->keys('[data-option="'.$second.'"]', 'Escape')
        ->assertScript(chipFocus('[data-filter-segment="value"]'), true)
        ->assertNoJavaScriptErrors();
});

it('picks tags from a tags chip and narrows the list', function (): void {
    customFieldOn($this->user, 'company', 'labels', 'tags-input');
    $companies = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->pluck('id', 'name');
    setCustomValue($this->user, Company::query()->withoutGlobalScopes()->findOrFail($companies['Acme Robotics']), 'company', 'labels', ['vip', 'lead']);
    setCustomValue($this->user, Company::query()->withoutGlobalScopes()->findOrFail($companies['Globex']), 'company', 'labels', ['vip']);
    $page = ($this->open)();
    expect(companyRows($page, 3))->toBeTrue();

    addChip($page, 'custom_fields.labels', 'has_any_of')
        ->click(optionButton('lead'));
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Acme Robotics')
        ->click(optionButton('vip'))
        ->assertSeeIn('[data-filter-segment="value"]', 'lead, vip');
    expect(companyRows($page, 2))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Enums\WorkspaceRole;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
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

function filterBarWaitFor(string $condition): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
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
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'created_at:asc'),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Hot leads');

    addChip($page, 'name', 'contains')->type(valueInput(), 'glo');
    $page->assertMissing('[data-view-save]');
    expect(companyRows($page, 1))->toBeTrue();

    $page->navigate("/app/{$this->workspace->slug}/companies")
        ->assertSeeIn('[data-filter-chip]', 'glo')
        ->assertMissing('[data-view-save]');
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
        ->assertSeeIn('[data-filter-chip]', '1,000')
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

it('lets a stored date chip change its condition and clears the value when the shape changes', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Made today',
        'state' => new TableStateData(filters: ['conditions' => ['match' => 'all', 'rules' => [['field' => 'created_at', 'condition' => 'is', 'value' => '@today']]]]),
    ]);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Made today')
        ->assertSeeIn('[data-filter-chip]', 'today');

    $page->assertScript('document.querySelector("[data-filter-chip] [data-filter-segment=condition]").tagName === "BUTTON"', true)
        ->assertScript('document.querySelector("[data-filter-chip] [data-filter-segment=value]").tagName === "BUTTON"', true);

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
        (async () => {
            const chip = document.querySelector('[data-filter-chip]');
            const nameOf = async (trigger, role) => {
                trigger.click();

                for (let attempt = 0; attempt < 50 && !chip.querySelector(`[role=${role}]`)?.checkVisibility(); attempt++) {
                    await new Promise((resolve) => setTimeout(resolve, 100));
                }

                const name = chip.querySelector(`[role=${role}]`).getAttribute('aria-label');

                trigger.click();

                return name;
            };

            return [
                await nameOf(chip.querySelector('[data-filter-segment=condition]'), 'listbox'),
                await nameOf(chip.querySelector('button[data-filter-segment=value]'), 'dialog'),
                await nameOf(chip.querySelector('[data-filter-more]'), 'menu'),
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
    $this->user->update(['name' => 'Dana Viewer']);
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
        ->assertScript(filterBarWaitFor('JSON.stringify([...document.querySelectorAll("[data-filter-segment=value] .fi-filter-value-label")].map((label) => label.textContent.trim())) === '.json_encode(json_encode(["{$this->user->name} (me)", 'Priya Shah']))), true);
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('lists the viewer once and first as the me entry with their photo and keeps it when their name is typed', function (): void {
    $this->user->update(['name' => 'Dana Viewer']);
    $this->workspace->users()->attach(User::factory()->create(['name' => 'Priya Shah']), ['role' => 'member']);
    $page = openOn($this->user, $this->workspace, 'opportunities');
    $entries = 'JSON.stringify([...document.querySelectorAll("[data-pop][data-open=true] [data-option]")].map((option) => [option.dataset.option, option.textContent.trim()]))';
    $settled = fn (): array => json_decode($page->script("new Promise((resolve) => setTimeout(() => resolve({$entries}), 1200))"), true);

    addChip($page, 'creator', 'is_any_of')->assertPresent(optionButton('@me').' img[src]');

    expect($settled())->toBe([['@me', 'Dana Viewer (me)'], [(string) $this->workspace->users()->where('name', 'Priya Shah')->value('users.id'), 'Priya Shah']]);

    $page->type(searchInput(), 'Dana');

    expect($settled())->toBe([['@me', 'Dana Viewer (me)']]);
    $page->assertScript('document.querySelector("[data-pop][data-open=true] [data-option=\\"@me\\"] img").getAttribute("alt") === ""', true)
        ->assertNoJavaScriptErrors();
});

it('marks a company lookup option with the company icon', function (): void {
    $acme = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->where('name', 'Acme Robotics')->value('id');
    $page = openOn($this->user, $this->workspace, 'people');

    addChip($page, 'company', 'is_any_of')
        ->assertPresent(optionButton((string) $acme).' [data-filter-mark].fi-square svg')
        ->assertScript('(() => { const mark = document.querySelector("[data-filter-mark]").getBoundingClientRect(); return mark.width > 0 && mark.width === mark.height; })()', true)
        ->assertNoJavaScriptErrors();
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

    expect($page->script($options))->not->toContain('Globex')->toContain("{$this->user->name} (me)");
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

function popVisible(): string
{
    return filterBarWaitFor('document.querySelector("[data-pop][data-open=true]")?.checkVisibility()');
}

function amountInput(): string
{
    return '[data-pop][data-open="true"] [data-filter-amount]';
}

function openOn(User $user, Workspace $workspace, string $list, int $width = 1440): AwaitableWebpage
{
    return loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 900)
        ->navigate("/app/{$workspace->slug}/{$list}")
        ->assertPresent('[data-filter-add]');
}

it('keeps a date in the next 30 days with a saved view and reads the same after a reload', function (): void {
    customFieldOn($this->user, 'company', 'renewal', 'date');
    foreach (['Soon' => 10, 'Later' => 90] as $name => $days) {
        setCustomValue($this->user, Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]), 'company', 'renewal', now()->addDays($days)->toDateString());
    }
    SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => 'Renewals']);

    $page = ($this->open)()
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('Renewals');
    expect(companyRows($page, 5))->toBeTrue();

    addChip($page, 'custom_fields.renewal', 'in_the_next');
    $page->assertScript(popVisible(), true)
        ->assertPresent('[data-pop] [data-filter-unit]')
        ->assertSeeIn('[data-pop]', 'Stays relative when this view is saved.')
        ->type(amountInput(), '30');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSeeIn('[data-filter-chip]', '30 days')
        ->assertMissing('[data-view-save]')
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertSeeIn('[data-filter-chip]', 'in the next')
        ->assertSeeIn('[data-filter-chip]', '30 days')
        ->assertMissing('[data-view-save]');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps a period chip unfinished until its amount fits the unit', function (): void {
    customFieldOn($this->user, 'company', 'renewal', 'date');

    $page = ($this->open)();
    addChip($page, 'custom_fields.renewal', 'in_the_past');
    $page->assertScript(popVisible(), true)->type(amountInput(), '0');
    $page->assertPresent('[data-filter-chip].fi-pending')
        ->type(amountInput(), '4000')
        ->assertPresent('[data-filter-chip].fi-pending')
        ->type(amountInput(), '12')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-chip]").dataset.complete === "true"'), true)
        ->assertSeeIn('[data-filter-chip]', '12 days')
        ->assertNoJavaScriptErrors();
});

it('narrows by created at this unit and says the choice stays relative', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old one', 'created_at' => now()->subDays(3)]);

    $page = ($this->open)();
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'created_at', 'this');
    $page->assertScript(popVisible(), true)
        ->assertSeeIn('[data-pop]', 'Stays relative when this view is saved.')
        ->click('[data-pop][data-open="true"] [data-unit="days"]');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertSeeIn('[data-filter-chip]', 'day')
        ->assertDontSee('Old one')
        ->assertNoJavaScriptErrors();
});

it('narrows by created at today with the Today shortcut', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old one', 'created_at' => now()->subDays(3)]);

    $page = ($this->open)();
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'created_at', 'is');
    $page->assertScript(popVisible(), true)
        ->assertPresent('[data-pop][data-open="true"] input[type="date"]')
        ->click('[data-filter-today]');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertSeeIn('[data-filter-chip]', 'today')
        ->assertDontSee('Old one')
        ->assertNoJavaScriptErrors();
});

it('reads a picked date in the viewer locale and filters by it', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old one', 'created_at' => '2026-03-04 10:00:00']);

    $page = ($this->open)();
    expect(companyRows($page, 4))->toBeTrue();

    addChip($page, 'created_at', 'is');
    $page->assertScript(popVisible(), true)
        ->type('[data-pop][data-open="true"] input[type="date"]', '2026-03-04');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSeeIn('[data-filter-chip]', 'Mar 4, 2026')
        ->assertSee('Old one')
        ->assertNoJavaScriptErrors();
});

it('narrows opportunities by days without activity', function (): void {
    $page = openOn($this->user, $this->workspace, 'opportunities');

    addChip($page, 'stale_days', 'no_activity_for');
    $page->assertScript(popVisible(), true)
        ->assertAttribute(valueInput(), 'type', 'number')
        ->type(valueInput(), '0')
        ->assertPresent('[data-filter-chip].fi-pending')
        ->type(valueInput(), '30')
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-chip]").dataset.complete === "true"'), true)
        ->assertScript(filterBarWaitFor('document.querySelector("[data-filter-chip]").innerText.replace(/\\s+/g, " ").trim() === "No activity for at least 30 days"'), true)
        ->assertNoJavaScriptErrors();
});

it('adds a deleted records chip last in the field list and switches it between included and only', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Gone'])->delete();

    $page = ($this->open)();
    expect(companyRows($page, 3))->toBeTrue();

    $page->click('[data-filter-add]')
        ->assertScript(popVisible(), true)
        ->assertScript('(() => { const items = [...document.querySelectorAll("[data-pop][data-open=true] [data-pop-item]")]; return items.at(-1).hasAttribute("data-filter-add-deleted") && items.length > 1; })()', true)
        ->click('[data-filter-add-deleted]');
    expect(companyRows($page, 4))->toBeTrue();
    $page->assertScript(filterBarWaitFor('document.querySelector("[data-filter-deleted] [data-filter-segment=value]")?.textContent.trim() === "included"'), true)
        ->assertScript(popVisible(), true)
        ->click('[data-deleted-mode="only"]');
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertSee('Gone')
        ->assertSeeIn('[data-filter-deleted]', 'only')
        ->click('[data-filter-add]')
        ->assertScript(popVisible(), true)
        ->assertPresent('[data-pop][data-open="true"] [data-pop-item]')
        ->assertMissing('[data-filter-add-deleted]')
        ->click('[data-deleted-remove]')
        ->assertMissing('[data-filter-deleted]');
    expect(companyRows($page, 3))->toBeTrue();
    $page->assertDontSee('Gone')->assertNoJavaScriptErrors();
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
        ->assertMissing('[data-view-save]')
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
        ->assertScript('document.querySelector("[data-pop][data-open=true] [data-field=name] .fi-filter-tick").checkVisibility()', true)
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
    $page = openOn($this->user, $this->workspace, 'opportunities');
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

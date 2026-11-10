<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Models\Company;
use App\Models\Opportunity;
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

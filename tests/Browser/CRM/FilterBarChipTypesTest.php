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

it('narrows opportunities with a number chip', function (): void {
    customFieldOn($this->user, 'opportunity', 'budget', 'number');
    foreach (['Small' => 100, 'Mid' => 1000, 'Big' => 5000] as $name => $budget) {
        setCustomValue($this->user, Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => $name]), 'opportunity', 'budget', $budget);
    }

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/opportunities?view=all", 1440, 900)
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

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/opportunities?view=all", 1440, 900)
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

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/opportunities?view=all", 1440, 900)
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
    $page = openOn($this->user, $this->workspace, 'opportunities?view=all');
    $entries = 'JSON.stringify([...document.querySelectorAll("[data-pop][data-open=true] [data-option]")].map((option) => [option.dataset.option, option.textContent.trim()]))';
    $settled = fn (): array => json_decode($page->wait(1.2)->script($entries), true);

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

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/people", 1440, 900)
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
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/opportunities?view=all", 1440, 900)
        ->assertPresent('[data-filter-add]');

    addChip($page, 'creator', 'is_any_of')
        ->click(optionButton('@me'))
        ->keys(searchInput(), 'Escape');
    addChip($page, 'company', 'is_any_of')
        ->type(searchInput(), 'glo')
        ->click('[data-field="creator"] [data-filter-segment="value"]')
        ->assertPresent(optionButton('@me'));

    $options = "[...document.querySelectorAll('[data-pop][data-open=true] [data-option]')].map((option) => option.textContent.trim())";

    expect($page->wait(1.2)->script($options))->not->toContain('Globex')->toContain("{$this->user->name} (me)");
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

function amountInput(): string
{
    return '[data-pop][data-open="true"] [data-filter-amount]';
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
        ->assertScript(filterRowSaveControlCount(), 0)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertSeeIn('[data-filter-chip]', 'in the next')
        ->assertSeeIn('[data-filter-chip]', '30 days')
        ->assertScript(filterRowSaveControlCount(), 0);
    expect(companyRows($page, 1))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps a period chip unfinished until its amount fits the unit', function (): void {
    customFieldOn($this->user, 'company', 'renewal', 'date');

    $page = ($this->open)();
    addChip($page, 'custom_fields.renewal', 'in_the_past');
    $page->assertScript(popVisible(), true)
        ->assertAttribute(amountInput(), 'placeholder', '1')
        ->type(amountInput(), '0');
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
    $page = openOn($this->user, $this->workspace, 'opportunities?view=all');

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

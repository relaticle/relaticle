<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Helpers\WorkspaceCustomField;

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    $this->company = Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create()->first();

    foreach (['Hot leads', 'Renewals'] as $name) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create([
            'name' => $name,
            'state' => new TableStateData(sort: 'name:desc'),
        ]);
    }
});

const BOARD_MAIN_ROWS = '.fi-board-main-view .fi-dropdown-list-item, .fi-board-main-view .fi-board-switch-row, .fi-board-main-view .fi-ta-views-row';

const BOARD_MAIN_ICONS = '.fi-board-main-view .fi-dropdown-list-item > svg, .fi-board-main-view .fi-board-switch-row > svg, .fi-board-main-view .fi-board-stage-grip svg, .fi-board-main-view .fi-board-stage-mark svg, .fi-board-main-view .fi-ta-views-row-actions svg';

function addBoardChoiceField(User $user): void
{
    resolve(CreateCustomField::class)->execute($user, [
        'entity_type' => 'opportunity',
        'name' => 'Region',
        'code' => 'region',
        'type' => 'select',
        'options' => [['name' => 'Americas'], ['name' => 'Europe']],
    ]);
}

const MERGE_PICKER_STEPS = ['.fi-record-rail-actions .fi-dropdown-trigger button', '.fi-dropdown-list-item:has-text("Merge record")'];

const CHIP_RULES = "js:Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: 'chip' }])";

const REGION_CHIP_RULES = "js:Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'custom_fields.region', condition: 'is_any_of', value: ['%option%'] }])";

const DELETED_CHIP = "js:Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setDeletedRecordsFilter', 'included')";

function addCompanyRegionField(User $user): void
{
    resolve(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Region',
        'code' => 'region',
        'type' => 'select',
        'options' => array_map(static fn (int $number): array => ['name' => "Region {$number}"], range(1, 14)),
    ]);
}

function runMenuSteps(AwaitableWebpage $page, array $steps): void
{
    foreach ($steps as $step) {
        if (str_starts_with($step, 'js:')) {
            $page->script(substr($step, 3));
            $page->wait(0.5);

            continue;
        }

        $page->click($step);
    }
}

it('draws every menu with the shared row, icon and corner spec', function (string $open, string $panel, string $rows, string $icons, string $path = 'companies', array $steps = [], array $differs = [], bool $region = false): void {
    if ($path === 'opportunities/board') {
        addBoardChoiceField($this->user);
    }

    if ($region) {
        addCompanyRegionField($this->user);
        $option = (string) WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'region')->options->first()->getKey();
        $steps = str_replace('%option%', $option, $steps);
    }

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/".str_replace('%company%', (string) $this->company->getKey(), $path), 1440, 900);

    runMenuSteps($page, $steps);

    $page->click($open)->assertVisible($panel);

    expect($page->script(menuMetrics($panel, $rows, $icons)))->toBe([
        'rows' => [32],
        'pitch' => [32],
        'icons' => [16],
        'panelRadius' => '10px',
        'rowRadius' => ['6px'],
        ...$differs,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'views' => ['.fi-view-switcher .fi-dropdown-trigger button', '.fi-ta-views-panel', '.fi-ta-views-row', '.fi-ta-views-save-current > svg'],
    'view settings' => ['.fi-view-settings-trigger', '.fi-view-settings-panel', '.fi-view-settings-row', '.fi-view-settings-handle > svg, [data-view-settings-add] > svg'],
    'filter field picker' => ['[data-filter-add]', '.fi-filter-pop-picker', '.fi-filter-item', '.fi-filter-item > svg'],
    'import and export' => ['.fi-header button.fi-ac-btn-group:visible', '.fi-header .fi-dropdown-panel:not([style*="display: none"])', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg'],
    'column header' => ["th[x-data^=\"resizableColumn('created_at'\"] [data-column-menu-trigger]", '.fi-ta-column-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg'],
    'board filter field picker' => ['[data-filter-add]', '.fi-filter-pop-picker', '.fi-filter-item', '.fi-filter-item > svg', 'opportunities/board'],
    'board sort menu' => ['[data-filter-sort]', '[data-sort-pop]', '.fi-filter-item', '.fi-filter-item > svg', 'opportunities/board'],
    'board view settings' => ['.fi-view-settings-trigger', '[data-board-view-settings]', BOARD_MAIN_ROWS, BOARD_MAIN_ICONS, 'opportunities/board'],
    'board grouped by' => ['[data-board-column-field-trigger]', '[data-board-view-settings]', '[data-board-panel="grouping"] .fi-dropdown-list-item', '[data-board-panel="grouping"] .fi-dropdown-list-item > svg', 'opportunities/board', ['.fi-view-settings-trigger']],
    'board visible columns' => ['[data-board-columns-trigger]', '[data-board-view-settings]', '[data-board-panel="columns"] .fi-ta-views-row', '[data-board-panel="columns"] .fi-board-stage-row svg', 'opportunities/board', ['.fi-view-settings-trigger']],
    'board lanes' => ['[data-board-lanes-trigger]', '[data-board-view-settings]', '[data-board-panel="lanes"] .fi-dropdown-list-item', '[data-board-panel="lanes"] .fi-dropdown-list-item > svg', 'opportunities/board', ['.fi-view-settings-trigger']],
    'board add attribute' => ['[data-board-add-attribute]', '[data-board-view-settings]', '[data-board-panel="add"] .fi-dropdown-list-item', '[data-board-panel="add"] .fi-dropdown-list-item > svg', 'opportunities/board', ['.fi-view-settings-trigger']],
    'board attribute row menu' => ['[data-board-view-settings] [data-attribute-actions] >> nth=0', '.fi-board-stages-row-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg', 'opportunities/board', ['.fi-view-settings-trigger']],
    'board import and export' => ['.fi-board-header-actions button.fi-ac-btn-group:visible', '.fi-board-header-actions .fi-dropdown-panel:not([style*="display: none"])', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg', 'opportunities/board'],
    'filter chip condition menu' => ['[data-filter-chip] [data-filter-segment="condition"]', '[data-filter-chip] .fi-filter-pop', '.fi-filter-item', '.fi-filter-item > svg', 'companies', [CHIP_RULES]],
    'filter chip value checklist' => ['[data-filter-chip] [data-filter-segment="value"]', '[data-filter-chip] .fi-filter-pop', '.fi-filter-item', '.fi-filter-box', 'companies', [REGION_CHIP_RULES], ['icons' => [14]], true],
    'filter group menu' => ['[data-filter-group]', '.fi-filter-pop', '.fi-filter-item', '.fi-filter-item > svg', 'tasks'],
    'filter chip kebab' => ['[data-filter-chip] [data-filter-more]', '[data-filter-chip] .fi-filter-pop', '.fi-filter-item', '.fi-filter-item > svg', 'companies', [CHIP_RULES], ['pitch' => []]],
    'filter deleted mode' => ['[data-deleted-mode-trigger]', '[data-filter-deleted] .fi-filter-pop', '.fi-filter-item', '.fi-filter-item > svg', 'companies', [DELETED_CHIP]],
    'merge record picker' => ['[data-merge-picker]', '.fi-merge-pop', '.fi-merge-pop li', '.fi-merge-pop svg', 'companies/%company%', MERGE_PICKER_STEPS],
    'views row menu' => ['.fi-ta-views-row[data-view-kind=list] [data-view-actions]', '.fi-ta-views-row-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg', 'companies', ['.fi-view-switcher .fi-dropdown-trigger button']],
    'view settings row menu' => ['[data-view-settings-menu] >> nth=0', '.fi-view-settings-row-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg', 'companies', ['.fi-view-settings-trigger'], ['pitch' => []]],
    'board stage row menu' => ['[data-stage-actions] >> nth=0', '.fi-board-stages-row-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg', 'opportunities/board', ['.fi-view-settings-trigger', '[data-board-columns-trigger]']],
]);

it('draws one keyboard focus ring on a header trigger, the filter button, a chip part and a column header', function (): void {
    $chip = "Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: 'chip' }])";

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900);

    $page->script($chip);
    $page->assertPresent('[data-filter-chip] [data-filter-segment="field"]')
        ->keys('.fi-view-settings-trigger', 'Tab');

    $rings = $page->script(<<<'JS'
        (() => {
            const controls = [
                '.fi-view-switcher .fi-dropdown-trigger > button',
                '.fi-view-settings-trigger',
                '[data-filter-add]',
                '[data-filter-sort]',
                '[data-filter-chip] [data-filter-segment="field"]',
                'th[x-data^="resizableColumn(\'created_at\'"] [data-column-menu-trigger]',
            ];

            return controls.map((selector) => {
                const element = document.querySelector(selector);
                element.focus();
                const style = getComputedStyle(element);

                return [element.matches(':focus-visible'), style.outlineStyle, style.outlineWidth, style.outlineColor].join(' | ');
            });
        })()
        JS);

    expect(array_unique($rings))->toHaveCount(1);
    expect($rings[0])->toStartWith('true | solid | 2px | ');
    $page->assertNoJavaScriptErrors();
});

it('draws a rule only between the groups a menu shows', function (array $steps, string $panel): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900);

    foreach ($steps as [$action, $selector]) {
        $page->{$action}($selector);
    }

    $page->assertVisible($panel);

    expect($page->script(<<<JS
        (() => {
            const groups = [...document.querySelector('{$panel}').children].filter((group) => group.getClientRects().length > 0);
            const first = getComputedStyle(groups[0]);
            const last = getComputedStyle(groups.at(-1));

            return { top: first.paddingTop, bottom: last.paddingBottom, rule: last.borderBottomWidth };
        })()
        JS))->toBe(['top' => '0px', 'bottom' => '0px', 'rule' => '0px']);
    $page->assertNoJavaScriptErrors();
})->with([
    'view settings on a column that cannot hide' => [[['click', '.fi-view-settings-trigger'], ['click', '[data-column-locked] [data-view-settings-menu]']], '.fi-view-settings-row-menu'],
    'views on the default list' => [[['click', '.fi-view-switcher .fi-dropdown-trigger button'], ['hover', '.fi-ta-views-row[data-view-kind=list]'], ['click', '.fi-ta-views-row[data-view-kind=list] [data-view-actions]']], '.fi-ta-views-row-menu'],
    'column header that cannot sort' => [[
        ['assertPresent', "th[x-data^=\"resizableColumn('custom_fields.domains'\"] [data-column-menu-trigger]"],
        ['script', "document.querySelector(`th[x-data^=\"resizableColumn('custom_fields.domains'\"] [data-column-menu-trigger]`).click()"],
    ], '.fi-ta-column-menu'],
]);

it('keeps a long menu above the window edge and scrolls only its list', function (string $open, string $panel, string $list, array $steps = [], bool $region = false, string $path = 'companies'): void {
    foreach (range(1, 8) as $index) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => "Pipeline {$index}"]);
    }

    Company::factory()->recycle([$this->user, $this->workspace])->count(12)->create();

    if ($region) {
        addCompanyRegionField($this->user);
        $option = (string) WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'region')->options->first()->getKey();
        $steps = str_replace('%option%', $option, $steps);
    }

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/".str_replace('%company%', (string) $this->company->getKey(), $path), 1440, 320);

    runMenuSteps($page, $steps);

    $page->click($open)->assertVisible($panel);

    expect($page->script(menuRoom($panel, $list)))->toBe([
        'height' => true,
        'bottom' => true,
        'listScrolls' => true,
        'panelScrolls' => false,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'views' => ['.fi-view-switcher .fi-dropdown-trigger button', '.fi-ta-views-panel', '.fi-ta-views-scroll'],
    'view settings' => ['.fi-view-settings-trigger', '.fi-view-settings-panel', '.fi-view-settings-scroll'],
    'filter field picker' => ['[data-filter-add]', '.fi-filter-pop-picker', '[role=listbox]'],
    'filter chip value checklist' => ['[data-filter-chip] [data-filter-segment="value"]', '[data-filter-chip] .fi-filter-pop', '.fi-filter-options', [REGION_CHIP_RULES], true],
    'merge record picker' => ['[data-merge-picker]', '.fi-merge-pop', '.fi-merge-pop ul', MERGE_PICKER_STEPS, false, 'companies/%company%'],
]);

function currentItem(string $current, string $other): string
{
    return <<<JS
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 300));

            const visible = (element) => element.getClientRects().length > 0;
            const current = [...document.querySelectorAll('{$current}')].find(visible);
            const other = [...document.querySelectorAll('{$other}')].find(visible);
            const label = (row) => row.querySelector('.fi-dropdown-list-item-label, .fi-filter-label') ?? row;
            const check = [...current.querySelectorAll('.fi-menu-check')].find(visible);

            return {
                check: check ? Math.round(check.getBoundingClientRect().width) : 0,
                trailing: check ? check.getBoundingClientRect().right > current.getBoundingClientRect().right - 48 : false,
                fill: getComputedStyle(current).backgroundColor,
                sameText: getComputedStyle(label(current)).color === getComputedStyle(label(other)).color,
                otherCheck: [...other.querySelectorAll('.fi-menu-check')].some(visible),
            };
        })()
        JS;
}

it('marks the current item with a trailing check and no fill', function (string $surface): void {
    $column = "th[x-data^=\"resizableColumn('created_at'\"] [data-column-menu-trigger]";
    $onBoard = str_starts_with($surface, 'board ');

    if ($onBoard) {
        addBoardChoiceField($this->user);
    }

    $path = match (true) {
        $onBoard => 'opportunities/board',
        $surface === 'filter group' => 'tasks',
        default => 'companies',
    };

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/{$path}", 1440, 900);

    if (! in_array($surface, ['views', 'filter group'], true) && ! $onBoard) {
        $page->click($column)->click('[data-column-menu-item="sortAscending"]')->assertPresent('[data-filter-sort-label]');
    }

    [$current, $other] = match ($surface) {
        'views' => ['.fi-ta-views-row:has([aria-current])', '.fi-ta-views-row:not(:has([aria-current]))'],
        'sort chooser' => ['[data-sort-pop] .fi-filter-item[aria-selected="true"]', '[data-sort-pop] .fi-filter-item[aria-selected="false"]'],
        'column header' => ['.fi-ta-column-menu [aria-checked="true"]', '.fi-ta-column-menu [aria-checked="false"]'],
        'board grouped by' => ['[data-board-column-field][aria-checked="true"]', '[data-board-column-field][aria-checked="false"]'],
        'board lanes' => ['[data-board-grouping][aria-checked="true"]', '[data-board-grouping][aria-checked="false"]'],
        'filter group' => ['[data-group-option][aria-checked="true"]', '[data-group-option][aria-checked="false"]'],
    };

    match ($surface) {
        'views' => $page->click('.fi-view-switcher .fi-dropdown-trigger button'),
        'sort chooser' => $page->click('[data-filter-sort]')->click('[data-sort-field]'),
        'column header' => $page->click($column),
        'board grouped by' => $page->click('.fi-view-settings-trigger')->click('[data-board-column-field-trigger]'),
        'board lanes' => $page->click('.fi-view-settings-trigger')->click('[data-board-lanes-trigger]'),
        'filter group' => $page->click('[data-filter-group]')->click('[data-group="priority_group"]'),
    };

    $page->hover('.fi-sidebar-nav .fi-sidebar-item-btn >> nth=0');

    expect($page->script(currentItem($current, $other)))->toBe([
        'check' => 16,
        'trailing' => true,
        'fill' => 'rgba(0, 0, 0, 0)',
        'sameText' => true,
        'otherCheck' => false,
    ]);
    $page->assertNoJavaScriptErrors();
})->with(['views', 'sort chooser', 'column header', 'board grouped by', 'board lanes', 'filter group']);

it('draws the board view settings with the shared row, icon and corner spec', function (string $path): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/{$path}", 1440, 900)
        ->click('.fi-view-settings-trigger')
        ->assertVisible('[data-board-view-settings]');

    expect($page->script(menuMetrics('[data-board-view-settings]', '.fi-ta-views-row', '.fi-ta-views-row-actions svg')))->toBe([
        'rows' => [32],
        'pitch' => [32],
        'icons' => [16],
        'panelRadius' => '10px',
        'rowRadius' => ['6px'],
    ]);
    $page->assertNoJavaScriptErrors();
})->with(['tasks/board', 'opportunities/board']);

it('keeps the board view settings above the window edge', function (array $steps, string $list, bool $listScrolls): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/opportunities/board", 1440, 320);

    foreach ($steps as $step) {
        $page->click($step);
    }

    $page->assertVisible('[data-board-view-settings]');

    expect($page->script(menuRoom('[data-board-view-settings]', $list)))->toBe([
        'height' => true,
        'bottom' => true,
        'listScrolls' => $listScrolls,
        'panelScrolls' => false,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'main panel' => [['.fi-view-settings-trigger'], '.fi-board-attributes-scroll', true],
    'add attribute panel' => [['.fi-view-settings-trigger', '[data-board-add-attribute]'], '[data-board-panel="add"] .fi-view-settings-scroll', true],
]);

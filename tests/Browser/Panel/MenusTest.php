<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\People;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\CustomFields\Services\TenantContextService;

function menuMetrics(string $panel, string $rows, string $icons): string
{
    return <<<JS
        (async () => {
            const panel = document.querySelector('{$panel}');
            const visible = (element) => element.getClientRects().length > 0;
            const visibleRows = () => [...panel.querySelectorAll('{$rows}')].filter(visible);
            const unique = (values) => [...new Set(values)];
            const settle = () => new Promise((resolve) => setTimeout(resolve, 100));

            for (let attempt = 0, count = -1; attempt < 20 && count !== visibleRows().length; attempt++) {
                count = visibleRows().length;
                await settle();
            }

            const rows = visibleRows();

            return {
                rows: unique(rows.map((row) => Math.round(row.getBoundingClientRect().height))),
                pitch: unique(rows.slice(1).map((row, index) => [row, rows[index]]).filter(([row, previous]) => row.parentElement === previous.parentElement).map(([row, previous]) => Math.round(row.getBoundingClientRect().top - previous.getBoundingClientRect().top))),
                icons: unique([...panel.querySelectorAll('{$icons}')].filter(visible).map((icon) => Math.round(icon.getBoundingClientRect().width))),
                panelRadius: getComputedStyle(panel.closest('.fi-dropdown-panel, .fi-filter-pop')).borderRadius,
                rowRadius: unique(rows.map((row) => getComputedStyle(row).borderRadius)),
            };
        })()
        JS;
}

function menuRoom(string $panel, string $list): string
{
    return <<<JS
        (() => {
            const panel = document.querySelector('{$panel}').closest('.fi-dropdown-panel, .fi-filter-pop');
            const box = panel.getBoundingClientRect();
            const list = panel.querySelector('{$list}');

            return {
                height: box.height <= 448,
                bottom: box.bottom <= window.innerHeight - 16,
                listScrolls: list.scrollHeight > list.clientHeight,
                panelScrolls: panel.scrollHeight > panel.clientHeight + 1,
            };
        })()
        JS;
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    foreach (['Hot leads', 'Renewals'] as $name) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create([
            'name' => $name,
            'state' => new TableStateData(sort: 'name:desc'),
        ]);
    }
});

it('draws every menu with the shared row, icon and corner spec', function (string $open, string $panel, string $rows, string $icons): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click($open)
        ->assertVisible($panel);

    expect($page->script(menuMetrics($panel, $rows, $icons)))->toBe([
        'rows' => [32],
        'pitch' => [32],
        'icons' => [16],
        'panelRadius' => '10px',
        'rowRadius' => ['6px'],
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'views' => ['.fi-view-switcher .fi-dropdown-trigger button', '.fi-ta-views-panel', '.fi-ta-views-row', '.fi-ta-views-save-current > svg'],
    'view settings' => ['.fi-view-settings-trigger', '.fi-view-settings-panel', '.fi-view-settings-row', '.fi-view-settings-handle > svg, [data-view-settings-add] > svg'],
    'filter field picker' => ['[data-filter-add]', '.fi-filter-pop-picker', '.fi-filter-item', '.fi-filter-item > svg'],
    'import and export' => ['.fi-header button.fi-ac-btn-group:visible', '.fi-header .fi-dropdown-panel:not([style*="display: none"])', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg'],
    'column header' => ["th[x-data^=\"resizableColumn('created_at'\"] [data-column-menu-trigger]", '.fi-ta-column-menu', '.fi-dropdown-list-item', '.fi-dropdown-list-item > svg'],
]);

it('draws a rule only between the groups a menu shows', function (array $steps, string $panel): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies");

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
    'column header that cannot sort' => [[['click', "th[x-data^=\"resizableColumn('custom_fields.domains'\"] [data-column-menu-trigger]"]], '.fi-ta-column-menu'],
]);

it('keeps a long menu above the window edge and scrolls only its list', function (string $open, string $panel, string $list): void {
    foreach (range(1, 8) as $index) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create(['name' => "Pipeline {$index}"]);
    }

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 320)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click($open)
        ->assertVisible($panel);

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

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies");

    if ($surface !== 'views') {
        $page->click($column)->click('[data-column-menu-item="sortAscending"]')->assertPresent('[data-filter-sort-label]');
    }

    [$current, $other] = match ($surface) {
        'views' => ['.fi-ta-views-row:has([aria-current])', '.fi-ta-views-row:not(:has([aria-current]))'],
        'sort chooser' => ['[data-sort-pop] .fi-filter-item[aria-selected="true"]', '[data-sort-pop] .fi-filter-item[aria-selected="false"]'],
        'column header' => ['.fi-ta-column-menu [aria-checked="true"]', '.fi-ta-column-menu [aria-checked="false"]'],
    };

    match ($surface) {
        'views' => $page->click('.fi-view-switcher .fi-dropdown-trigger button'),
        'sort chooser' => $page->click('[data-filter-sort]')->click('[data-sort-field]'),
        'column header' => $page->click($column),
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
})->with(['views', 'sort chooser', 'column header']);

function holdLook(array $targets): string
{
    $selectors = json_encode($targets);

    return <<<JS
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 400));

            return Object.fromEntries({$selectors}.map((selector) => {
                const element = [...document.querySelectorAll(selector.replace(':visible', ''))].find((candidate) => candidate.getClientRects().length > 0);
                const style = getComputedStyle(element);

                return [selector, [style.backgroundColor, style.color, style.boxShadow, style.borderRightColor].join(' | ')];
            }));
        })()
        JS;
}

function expectHoverHeldWhileOpen(AwaitableWebpage $page, string $item, string $trigger): void
{
    $away = '.fi-sidebar-nav .fi-sidebar-item-btn >> nth=0';
    $targets = array_values(array_unique([$item, $trigger]));

    $page->hover($item)->hover($trigger);
    $hoveredTrigger = $page->script(holdLook([$trigger]))[$trigger];
    $page->hover($item);
    $hovered = $page->script(holdLook($targets));
    $hovered[$trigger] = $hoveredTrigger;

    $page->hover($away);
    $resting = $page->script(holdLook($targets));
    expect($resting)->not->toBe($hovered);

    $page->click($trigger)->assertAttribute($trigger, 'aria-expanded', 'true');
    $page->script('document.activeElement.blur()');
    $page->hover($away);

    expect($page->script(holdLook($targets)))->toBe($hovered);
}

it('keeps an item looking hovered while the menu opened from it is open', function (string $surface): void {
    $user = $this->user;
    $workspace = $this->workspace;
    $company = Company::query()->withoutGlobalScopes()->first();
    $company->tasks()->attach(Task::factory()->recycle([$user, $workspace])->create());

    $chip = "js:Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: 'chip' }])";

    [$path, $prepare, $item, $trigger] = match ($surface) {
        'relation row' => ["companies/{$company->getKey()}", ['.fi-record-pane .fi-tabs-item:nth-child(4)'], '.fi-ta-row', '.fi-ta-row .fi-dropdown-trigger > button'],
        'column header' => ['companies', [], 'th[x-data^="resizableColumn(\'created_at\'"]', 'th[x-data^="resizableColumn(\'created_at\'"] [data-column-menu-trigger]'],
        'bulk bar more' => ['companies', ['.fi-ta-record-checkbox >> nth=0'], '.fi-bulk-bar .fi-dropdown-trigger > button', '.fi-bulk-bar .fi-dropdown-trigger > button'],
        'import and export' => ['companies', [], '.fi-header button.fi-ac-btn-group:visible', '.fi-header button.fi-ac-btn-group:visible'],
        'view switcher' => ['companies', [], '.fi-view-switcher .fi-dropdown-trigger button', '.fi-view-switcher .fi-dropdown-trigger button'],
        'view settings' => ['companies', [], '.fi-view-settings-trigger', '.fi-view-settings-trigger'],
        'filter add' => ['companies', [], '[data-filter-add]', '[data-filter-add]'],
        'filter sort' => ['companies', [], '[data-filter-sort]', '[data-filter-sort]'],
        'filter field segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="field"]', '[data-filter-chip] [data-filter-segment="field"]'],
        'filter condition segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="condition"]', '[data-filter-chip] [data-filter-segment="condition"]'],
        'filter value segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="value"]', '[data-filter-chip] [data-filter-segment="value"]'],
        'filter group' => ['tasks', [], '[data-filter-group]', '[data-filter-group]'],
        'notifications' => ['companies', [], '.fi-sidebar-notifications-btn', '.fi-sidebar-notifications-btn'],
        'record actions' => ["companies/{$company->getKey()}", [], '.fi-record-page .fi-ac-btn-group:visible', '.fi-record-page .fi-ac-btn-group:visible'],
        'workspace menu' => ['companies', [], '.fi-tenant-menu-trigger', '.fi-tenant-menu-trigger'],
        'filter more segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-more]', '[data-filter-chip] [data-filter-more]'],
    };

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}");

    foreach ($prepare as $step) {
        if (str_starts_with($step, 'js:')) {
            $page->script(substr($step, 3));
            $page->wait(0.5);

            continue;
        }

        $page->click($step);
    }

    expectHoverHeldWhileOpen($page, $item, $trigger);

    $page->assertNoJavaScriptErrors();
})->with(['relation row', 'column header', 'bulk bar more', 'import and export', 'view switcher', 'view settings', 'filter add', 'filter sort', 'filter field segment', 'filter condition segment', 'filter value segment', 'filter more segment', 'filter group', 'notifications', 'record actions', 'workspace menu']);

it('keeps a board card looking hovered while its menu is open', function (): void {
    TenantContextService::setTenantId($this->workspace->getKey());
    $statusField = CustomField::query()->forEntity(Task::class)->where('code', TaskField::STATUS->value)->firstOrFail();
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Send the renewal quote']);
    $task->saveCustomFieldValue($statusField, $statusField->options->first()->getKey());
    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$task->getKey()}\"]";

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/tasks/board")
        ->assertVisible($card);

    expectHoverHeldWhileOpen($page, $card, "{$card} .ff-card-actions .fi-dropdown-trigger > button");
    $page->assertNoJavaScriptErrors();
});

it('keeps a sidebar chat looking hovered while its menu is open', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'cb1',
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'Acme onboarding',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertSee('Acme onboarding');

    expectHoverHeldWhileOpen($page, '.fi-sidebar-item:has([aria-label="Chat actions"]) .fi-sidebar-item-btn', '.fi-sidebar-item [aria-label="Chat actions"]');
    $page->assertNoJavaScriptErrors();
});

it('keeps a list row looking hovered while its relations menu is open', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach(People::factory()->count(3)->recycle([$this->user, $this->workspace])->create());

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/notes/list")
        ->assertVisible('.fi-linked-records-cell-more');

    expectHoverHeldWhileOpen($page, '.fi-ta-row', '.fi-linked-records-cell-more');
    $page->assertNoJavaScriptErrors();
});

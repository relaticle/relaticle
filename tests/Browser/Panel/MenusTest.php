<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;

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

<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Models\Company;
use App\Models\TablePreference;
use App\Models\User;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

mutates(HasColumnMenu::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

function pinFromMenu(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    $page->script(openColumnMenu($column));
    $page->click(columnMenuItem('pin'));

    return waitUntil($page, sprintf('document.querySelector(`%s`)?.dataset.columnPinned === "1"', columnHeader($column)));
}

function firstCell(string $column): string
{
    return sprintf('document.querySelector(`tbody td.fi-ta-cell-%s`)', Str::of($column)->camel()->kebab()->replace('.', '\\\\.'));
}

function leftEdges(string ...$elements): string
{
    return '['.implode(', ', array_map(fn (string $element): string => "Math.round({$element}.getBoundingClientRect().left)", $elements)).']';
}

function wideCompaniesTable(User $user, string $slug): AwaitableWebpage
{
    TablePreference::factory()->for($user)->for($user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['accountOwner.name' => 700, 'creator.name' => 700]),
    ]);

    return companiesTable($user, $slug);
}

it('pins a column from its menu and keeps the pin after a reload', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at')
        ->assertScript(headerOrder().'.slice(0, 2)', ['created_at', 'name'])
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('name')), '0')
        ->refresh()
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]')
        ->assertScript(headerOrder().'.slice(0, 2)', ['created_at', 'name'])
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('created_at')), '1');

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertScript(visibleMenuItems(), ['Sort ascending', 'Sort descending', 'Unpin', 'Edit column label', 'Hide from view'])
        ->assertNoJavaScriptErrors();
});

it('pins the title column like any other and unpins a column from its lock', function (): void {
    $page = pinFromMenu(pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'name'), 'created_at')
        ->assertScript(headerOrder().'.slice(0, 2)', ['name', 'created_at']);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, visibleMenuItems().'.join() === "Sort ascending,Sort descending,Unpin,Edit column label,Hide from view"');
    $page->click(columnMenuItem('unpin'));
    waitUntil($page, sprintf('document.querySelector(`%s`).dataset.columnPinned === "0"', columnHeader('created_at')))
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('name')), '1')
        ->assertNoJavaScriptErrors();
});

it('never drags a pinned column or drops another among the pins', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at');
    $order = $page->script(headerOrder());

    expect($page->script(dragHeader('created_at', end($order))))->toBe(['ghost' => false, 'copy' => false, 'shifted' => false, 'dragging' => false]);

    $page->script(dragHeader(end($order), 'created_at'));
    waitUntil($page, headerOrder().'.indexOf('.json_encode(end($order)).') === 1')
        ->assertScript(headerOrder().'[0]', 'created_at')
        ->assertNoJavaScriptErrors();
});

it('offers no way to move a pinned column from view settings or its header', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at');

    openViewSettings($page)
        ->assertMissing(settingsRow('created_at', '.fi-view-settings-grip'))
        ->assertPresent(settingsRow('created_at', '.fi-view-settings-pin'))
        ->assertPresent(settingsRow('name', '.fi-view-settings-grip'))
        ->assertScript(settingsOrder().'.slice(0, 2)', ['created_at', 'name'])
        ->assertScript(sprintf('getComputedStyle(document.querySelector(`%s`)).cursor !== "move"', columnHeader('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('keeps pinned columns in place while the table scrolls sideways', function (): void {
    $page = pinFromMenu(pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'name'), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');
    $edges = leftEdges('document.querySelector("tbody .fi-ta-selection-cell")', "document.querySelector(`th[x-data^=\"resizableColumn('name'\"]`)", firstCell('name'), firstCell('created_at'));
    $before = $page->script($edges);

    $page->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")')
        ->assertScript($edges, $before)
        ->assertScript(sprintf('(() => { const cell = %s; const box = cell.getBoundingClientRect(); return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest("td") === cell })()', firstCell('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('moves the pinned columns after a pinned column that is resized', function (): void {
    $page = pinFromMenu(pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'name'), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');
    $before = (int) $page->script('parseInt('.firstCell('created_at').'.style.left, 10)');

    $page->script(sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerId: 1, isPrimary: true, clientX: x, clientY: y }));
            document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerId: 1, isPrimary: true, clientX: x + 60, clientY: y }));
            return true;
        })();
    JS, columnHeader('name')));

    $page->assertScript('parseInt('.firstCell('created_at').'.style.left, 10)', $before + 60);
    $page->script('window.__saved = 0; Livewire.hook("request", ({ succeed }) => succeed(() => { window.__saved++ })); true');
    $page->script('document.dispatchEvent(new PointerEvent("pointerup", { bubbles: true, pointerId: 1, isPrimary: true }))');

    waitUntil($page, 'window.__saved > 0')
        ->assertScript('parseInt('.firstCell('created_at').'.style.left, 10)', $before + 60)
        ->assertNoJavaScriptErrors();
});

it('keeps pins stuck while a full column still fits beside them', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(1440, 900)->wait(0.5);

    foreach (['accountOwner.name', 'creator.name', 'created_at'] as $column) {
        pinFromMenu($page, $column);
    }

    $page->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")')
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', true)
        ->assertScript(sprintf('(() => { const cell = %s; const box = cell.getBoundingClientRect(); return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest("td") === cell })()', firstCell('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('lets three pins scroll with the table on a tablet, so the last column can be read in full', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(768, 1024)->wait(0.5);

    foreach (['name', 'accountOwner.name', 'creator.name'] as $column) {
        pinFromMenu($page, $column);
    }

    $page->wait(1)->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")')
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', false)
        ->assertScript(sprintf('(() => { const box = document.querySelector(`%s`).getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth })()', columnHeader('custom_fields.domains')), true)
        ->assertNoJavaScriptErrors();
});

it('keeps a pin stuck on a tablet-sized screen', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug)->resize(768, 1024)->wait(0.5), 'name');

    $page->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")')
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', true)
        ->assertScript(sprintf('(() => { const cell = %s; const box = cell.getBoundingClientRect(); return box.left < window.innerWidth && document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest("td") === cell })()', firstCell('name')), true)
        ->assertNoJavaScriptErrors();
});

it('lets pins that leave no room for another column scroll with the table on a narrow tablet', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug)->resize(640, 900)->wait(0.5), 'name');
    $page = pinFromMenu($page, 'accountOwner.name');
    $page = pinFromMenu($page, 'created_at');

    $page->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")')
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', false)
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('created_at')), '1')
        ->assertScript('[...document.querySelectorAll(`th[x-data^="resizableColumn"][data-column-pinned="0"]`)].some((th) => { const box = th.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth })', true)
        ->assertNoJavaScriptErrors();
});

it('shows each row as a card on a phone, headed by its name, without the pins and widths the member saved', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['updated_at' => 400], pinned: ['accountOwner.name']),
    ]);

    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    waitUntil($page, 'document.querySelector("table.fi-ta-table").style.tableLayout === ""')
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', false)
        ->assertScript(sprintf('Math.round(%s.getBoundingClientRect().width) > 300', firstCell('updated_at')), true)
        ->assertScript(sprintf('%s.getBoundingClientRect().right <= window.innerWidth', firstCell('updated_at')), true)
        ->assertScript(<<<'JS'
            (() => {
                const cells = [...document.querySelector('tbody tr.fi-ta-row').children]
                    .filter((td) => td.matches('td.fi-ta-cell:not(.fi-ta-selection-cell)') && !td.querySelector('.fi-ta-actions') && getComputedStyle(td).display !== 'none')
                    .sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);

                return cells[0].classList.contains('fi-ta-cell-name');
            })()
        JS, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the column menu on screen beside a column at the left edge', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    $page->script(openColumnMenu('name'));
    waitUntil($page, menuItemFocused())
        ->assertScript('Math.round(document.querySelector(".fi-ta-column-menu").getBoundingClientRect().left)', 8)
        ->assertNoJavaScriptErrors();
});

it('opens the menu of a pinned column at the minimum width from a real click on its header', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['created_at' => 40]),
    ]);
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at');
    $page->keys(':focus', 'Escape');
    $label = columnHeader('created_at').' .fi-ta-resizable-label';

    $page->assertScript(sprintf(<<<'JS'
        (() => {
            const box = document.querySelector(`%s`).getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            return !!hit && document.querySelector(`%s`).contains(hit);
        })();
    JS, $label, columnHeader('created_at')), true);
    $page->click($label);

    waitUntil($page, menuItemFocused())
        ->assertNoJavaScriptErrors();
});

it('tints pinned cells like the rest of a selected row', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at');

    $page->script('document.querySelector("tbody tr.fi-ta-row .fi-ta-record-checkbox").click()');

    waitUntil($page, 'document.querySelector("tbody tr.fi-ta-row").classList.contains("fi-selected")')
        ->assertScript(sprintf('getComputedStyle(%s).backgroundColor === getComputedStyle(document.querySelector("tbody tr.fi-ta-row")).backgroundColor', firstCell('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('keeps the handles of scrolled headers under the pinned headers', function (): void {
    $page = pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');

    $covered = $page->script(sprintf(<<<'JS'
        (() => {
            const wrapper = document.querySelector('.fi-ta-content-ctn');
            const pinned = document.querySelector(`%s`).getBoundingClientRect();
            const handle = document.querySelector(`%s [data-column-menu-trigger]`);
            wrapper.scrollLeft += handle.getBoundingClientRect().left - (pinned.left + pinned.width / 2);
            handle.style.opacity = '1';
            const box = handle.getBoundingClientRect();
            return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest('th')?.dataset.columnPinned;
        })();
    JS, columnHeader('created_at'), columnHeader('accountOwner.name')));

    expect($covered)->toBe('1');
    $page->assertNoJavaScriptErrors();
});

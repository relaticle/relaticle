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

function dragHeader(string $column, string $overColumn, string $side = 'left', string $finish = 'drop', int $dropBelow = 0): string
{
    return sprintf(<<<'JS'
        (async () => {
            const grip = document.querySelector(`%s .fi-ta-resizable-label`);
            const over = document.querySelector(`%s`).getBoundingClientRect();
            const start = grip.getBoundingClientRect();
            const x = over.left + over.width * (%s === 'left' ? 0.25 : 0.75);
            const y = start.top + start.height / 2;
            const pointer = (type, clientX, clientY, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, isPrimary: true, clientX, clientY }));
            pointer('pointerdown', start.left + 2, y, grip);
            pointer('pointermove', start.left + 12, y);
            pointer('pointermove', x, y);
            await new Promise(requestAnimationFrame);
            const lifted = document.querySelector('.fi-ta-column-drag-ghost');
            const header = grip.closest('th');
            const originals = [...header.closest('table').querySelectorAll(`td.fi-ta-cell-${CSS.escape(header.dataset.resizableColumnId)}`), grip];
            const copies = lifted ? [...lifted.children] : [];
            const shown = (element) => { const clone = element.cloneNode(true); clone.querySelectorAll('.fi-ta-cell-label').forEach((label) => label.remove()); return clone; };
            const media = (element) => shown(element).querySelectorAll('svg, img').length;
            const mid = {
                ghost: (lifted?.isConnected && lifted.inert) ?? false,
                copy: copies.length === originals.length && originals.every((original, index) => copies[index].offsetHeight === (original === grip ? header : original).offsetHeight && !copies[index].querySelector('.fi-ta-cell-label') && shown(copies[index]).textContent.trim() === shown(original).textContent.trim() && media(copies[index]) === media(original)),
                shifted: document.querySelector(`%s`).style.transform !== '',
                dragging: header.classList.contains('fi-ta-column-dragging'),
            };
            if (%s === 'escape') {
                document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
                pointer('pointerup', x, y);
            } else {
                pointer('pointerup', x, y + %d);
            }
            return mid;
        })();
    JS, columnHeader($column), columnHeader($overColumn), json_encode($side), columnHeader($overColumn), json_encode($finish), $dropBelow);
}

function countLivewireRequests(): string
{
    return 'window.__requests = 0; Livewire.hook("request", () => { window.__requests++ }); true';
}

function dragLeftovers(): string
{
    return '!!document.querySelector(".fi-ta-column-drag-ghost") || !!document.querySelector(".fi-ta-column-dragging") || [...document.querySelectorAll("th, td")].some((cell) => cell.style.transform !== "")';
}

it('drops a dragged header before another and keeps the order after a reload', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $last = end($order);

    $page->script(dragHeader($last, $order[1]));

    waitUntil($page, headerOrder().'.indexOf('.json_encode($last).') === 1')
        ->assertScript(dragLeftovers(), false)
        ->wait(1)
        ->refresh()
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]')
        ->assertScript(headerOrder().'.indexOf('.json_encode($last).')', 1)
        ->assertNoJavaScriptErrors();
});

it('lifts the header and slides the other columns aside mid-drag', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $feedback = $page->script(dragHeader('created_at', 'accountOwner.name', finish: 'escape'));

    expect($feedback)->toBe(['ghost' => true, 'copy' => true, 'shifted' => true, 'dragging' => true]);
    $page->assertScript(dragLeftovers(), false);
    $page->assertNoJavaScriptErrors();
});

it('drags the title column and drops another column before it', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());

    $page->script(dragHeader('name', $order[1], side: 'right'));
    waitUntil($page, headerOrder().'.indexOf("name") > 0');

    $page->script(dragHeader('created_at', 'name'));
    waitUntil($page, '(() => { const order = '.headerOrder().'; return order.indexOf("created_at") === order.indexOf("name") - 1; })()')
        ->assertScript(dragLeftovers(), false)
        ->assertNoJavaScriptErrors();
});

it('sends nothing for a drag released in place, outside the header row, or cancelled with Escape', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $page->script(countLivewireRequests());

    $page->script(dragHeaderBy('creator.name', $order[array_search('creator.name', $order, true) + 1], 0.2));
    $page->script(dragHeader('creator.name', 'accountOwner.name', dropBelow: 600));
    $page->script(dragHeader('creator.name', 'accountOwner.name', finish: 'escape'));
    $page->wait(1);

    $page->assertScript('window.__requests', 0)
        ->assertScript(headerOrder(), $order)
        ->assertScript(dragLeftovers(), false)
        ->assertNoJavaScriptErrors();
});

it('does not start a drag from the resize handle', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $before = $page->script(sprintf('Math.round(document.querySelector(`%s`).getBoundingClientRect().width)', columnHeader('created_at')));

    $sawGhost = $page->script(sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerId: 1, clientX: x, clientY: y }));
            handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0, clientX: x, clientY: y }));
            document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerId: 1, clientX: x + 60, clientY: y }));
            document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: x + 60, clientY: y }));
            const ghost = !!document.querySelector('.fi-ta-column-drag-ghost');
            document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1, clientX: x + 60, clientY: y }));
            document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: x + 60, clientY: y }));
            return ghost;
        })();
    JS, columnHeader('created_at')));

    expect($sawGhost)->toBeFalse();
    $page->assertScript(sprintf('Math.round(document.querySelector(`%s`).getBoundingClientRect().width)', columnHeader('created_at')), $before + 60)
        ->assertNoJavaScriptErrors();
});

it('scrolls the table sideways while a header is dragged against its edge', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(900, 900);

    $page->script(sprintf(<<<'JS'
        (() => {
            const grip = document.querySelector(`%s .fi-ta-resizable-label`);
            const wrapper = document.querySelector('.fi-ta-content-ctn');
            const start = grip.getBoundingClientRect();
            const edge = wrapper.getBoundingClientRect().right - 8;
            const y = start.top + start.height / 2;
            const pointer = (type, clientX, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, clientX, clientY: y }));
            pointer('pointerdown', start.left + 2, grip);
            pointer('pointermove', start.left + 20);
            pointer('pointermove', edge);
            window.__dragEdge = edge;
            window.__dragY = y;
            return true;
        })();
    JS, columnHeader('accountOwner.name')));

    waitUntil($page, 'document.querySelector(".fi-ta-content-ctn").scrollLeft > 100');

    $page->script('document.dispatchEvent(new PointerEvent("pointerup", { bubbles: true, pointerId: 1, clientX: window.__dragEdge, clientY: window.__dragY }))');

    waitUntil($page, headerOrder().'.indexOf("accountOwner.name") > 3')
        ->assertScript(dragLeftovers(), false)
        ->assertNoJavaScriptErrors();
});

function dragHeaderBy(string $column, string $neighbour, float $share): string
{
    return sprintf(<<<'JS'
        (() => {
            const grip = document.querySelector(`%s .fi-ta-resizable-label`);
            const neighbour = document.querySelector(`%s`).getBoundingClientRect();
            const start = grip.getBoundingClientRect();
            const x = start.left + 2;
            const y = start.top + start.height / 2;
            const dx = Math.sign(neighbour.left - start.left) * neighbour.width * %F;
            const pointer = (type, clientX, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, clientX, clientY: y }));
            pointer('pointerdown', x, grip);
            pointer('pointermove', x + Math.sign(dx) * 10);
            pointer('pointermove', x + dx);
            pointer('pointerup', x + dx);
            return true;
        })();
    JS, columnHeader($column), columnHeader($neighbour), $share);
}

it('swaps with a neighbour on either side once the dragged column covers half of it', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $index = array_search('creator.name', $order, true);

    $page->script(dragHeaderBy('creator.name', $order[$index + 1], 0.4));
    $page->wait(0.5)->assertScript(headerOrder(), $order);

    $page->script(dragHeaderBy('creator.name', $order[$index + 1], 0.6));
    waitUntil($page, headerOrder().'.indexOf("creator.name") === '.($index + 1));

    $page->script(dragHeaderBy('creator.name', $order[$index + 1], 0.6));
    waitUntil($page, headerOrder().'.indexOf("creator.name") === '.$index)
        ->assertNoJavaScriptErrors();
});

it('focuses the title column after hiding the last column beside it by keyboard', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    foreach (array_diff($page->script(headerOrder()), ['name', 'created_at']) as $column) {
        $page->script(openColumnMenu($column));
        $page->click(columnMenuItem('hide'))->assertMissing(columnHeader($column));
    }

    $page->keys(columnHeader('created_at').' [data-column-menu-trigger]', 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', ['ArrowUp', 'Enter']);
    waitUntil($page, headerOrder().'.join() === "name"');
    waitUntil($page, '('.focusedColumn().') === "name"')
        ->assertNoJavaScriptErrors();
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

function wideCompaniesTable(User $user, string $slug): AwaitableWebpage
{
    TablePreference::factory()->for($user)->for($user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['accountOwner.name' => 700, 'creator.name' => 700]),
    ]);

    return companiesTable($user, $slug);
}

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

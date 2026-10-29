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

function companiesTable(User $user, string $slug): AwaitableWebpage
{
    return loginViaBrowser($user)
        ->assertPathIs("/app/{$slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$slug}/companies")
        ->assertPresent(columnHeader('created_at').' .fi-ta-column-handle');
}

function columnHeader(string $column): string
{
    return "th[x-data^=\"resizableColumn('{$column}'\"]";
}

function headerOrder(): string
{
    return '[...document.querySelectorAll(`th[x-data^="resizableColumn"]`)].map((th) => th.getAttribute("x-data").match(/^resizableColumn\(\'([^\']+)\'/)[1])';
}

function openColumnMenu(string $column): string
{
    return sprintf('document.querySelector(`%s .fi-ta-column-handle`).click()', columnHeader($column));
}

function columnMenuItem(string $label): string
{
    return ".fi-ta-column-menu [role=\"menuitem\"][data-label=\"{$label}\"]";
}

function visibleMenuItems(): string
{
    return '[...document.querySelectorAll(".fi-ta-column-menu [role=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.dataset.label)';
}

function menuItemFocused(): string
{
    return 'document.activeElement.getAttribute("role") === "menuitem"';
}

function waitUntil(AwaitableWebpage $page, string $expression): AwaitableWebpage
{
    foreach (range(1, 50) as $attempt) {
        if ($page->script($expression) === true) {
            return $page;
        }

        $page->wait(0.1);
    }

    return $page->assertScript($expression, true);
}

function focusedColumn(): string
{
    return 'document.activeElement.closest(`th[x-data^="resizableColumn"]`)?.getAttribute("x-data").match(/^resizableColumn\(\'([^\']+)\'/)[1] ?? null';
}

it('moves and hides a column from its handle menu and keeps it after a reload', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $index = array_search('creator.name', $order, true);

    $page->script(openColumnMenu('creator.name'));
    $page->click(columnMenuItem('Move right'))
        ->assertScript(headerOrder().'.indexOf("creator.name")', $index + 1);

    $beforeHidden = $page->script('(() => { const order = '.headerOrder().'; const at = order.indexOf("accountOwner.name"); return at === 1 ? order[2] : order[at - 1]; })()');

    $page->script(openColumnMenu('accountOwner.name'));
    $page->click(columnMenuItem('Hide column'))
        ->assertMissing(columnHeader('accountOwner.name'));
    waitUntil($page, '('.focusedColumn().') === '.json_encode($beforeHidden))
        ->wait(1)
        ->refresh()
        ->assertVisible(columnHeader('creator.name'))
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertScript(headerOrder().'.indexOf("creator.name") > '.headerOrder().'.indexOf("created_at")', true)
        ->assertNoJavaScriptErrors();
});

it('keeps sorting on the header and leaves the title column without a handle', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)
        ->assertMissing(columnHeader('name').' .fi-ta-column-handle');

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', columnHeader('created_at')));
    waitUntil($page, sprintf('document.querySelector(`%s`).getAttribute("aria-sort") === "ascending"', columnHeader('created_at')));

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertMissing('.fi-ta-column-menu [data-label="Sort ascending"]')
        ->assertScript(visibleMenuItems(), ['Move left', 'Move right', 'Pin to left', 'Hide column'])
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-sort")', columnHeader('created_at')), 'ascending')
        ->assertNoJavaScriptErrors();
});

it('disables the moves at the edges', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());

    $page->script(openColumnMenu($order[1]));
    waitUntil($page, menuItemFocused())
        ->assertAttribute(columnMenuItem('Move left'), 'aria-disabled', 'true')
        ->keys(':focus', 'Escape');

    $page->script(openColumnMenu(end($order)));
    waitUntil($page, menuItemFocused())
        ->assertAttribute(columnMenuItem('Move right'), 'aria-disabled', 'true')
        ->assertNoJavaScriptErrors();
});

it('moves a column right past a hidden one', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('creator.name'));
    $page->click(columnMenuItem('Hide column'))
        ->assertMissing(columnHeader('creator.name'));

    $order = $page->script(headerOrder());
    $next = $order[array_search('accountOwner.name', $order, true) + 1];

    $page->script(openColumnMenu('accountOwner.name'));
    $page->click(columnMenuItem('Move right'))
        ->assertScript(headerOrder().'.indexOf("accountOwner.name")', array_search($next, $order, true))
        ->assertNoJavaScriptErrors();
});

it('works the handle by keyboard alone and keeps focus on the moved column', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $index = array_search('creator.name', $page->script(headerOrder()), true);
    $positionIs = fn (int $position): string => headerOrder().'.indexOf("creator.name") === '.$position;
    $handleFocused = '(document.activeElement.classList.contains("fi-ta-column-handle") && ('.focusedColumn().') === "creator.name")';
    $handle = columnHeader('creator.name').' .fi-ta-column-handle';

    $page->keys($handle, 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', ['ArrowDown', 'Enter']);
    waitUntil($page, $positionIs($index + 1));
    waitUntil($page, $handleFocused);

    $page->keys($handle, 'ArrowLeft');
    waitUntil($page, $positionIs($index));
    waitUntil($page, $handleFocused);

    $page->keys($handle, 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', 'Escape');
    waitUntil($page, $handleFocused)
        ->assertMissing(columnMenuItem('Move left'))
        ->assertNoJavaScriptErrors();
});

it('does not open the menu after a drag that ends on the handle', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .fi-ta-column-handle`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const pointer = (type, clientX, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, clientX, clientY: y }));
            pointer('pointerdown', x, handle);
            pointer('pointermove', x + 30);
            pointer('pointermove', x);
            pointer('pointerup', x);
            handle.click();
            return true;
        })();
    JS, columnHeader('creator.name')));

    $page->wait(0.3)
        ->assertMissing(columnMenuItem('Move left'))
        ->assertNoJavaScriptErrors();
});

it('keeps the column manager order in step with the headers', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    $page->click(columnMenuItem('Move left'));
    $page->wait(1);

    $headers = $page->script(headerOrder());
    $manager = $page->script('[...document.querySelectorAll(".fi-ta-col-manager-items [x-sortable-item]")].map((item) => item.getAttribute("x-sortable-item").replace(/^column::/, ""))');

    expect(array_values(array_intersect($manager, $headers)))->toBe($headers);
    $page->assertNoJavaScriptErrors();
});

function dragHeader(string $column, string $overColumn, string $side = 'left', string $finish = 'drop', int $dropBelow = 0): string
{
    return sprintf(<<<'JS'
        (() => {
            const grip = document.querySelector(`%s .fi-ta-column-handle`);
            const over = document.querySelector(`%s`).getBoundingClientRect();
            const start = grip.getBoundingClientRect();
            const x = over.left + over.width * (%s === 'left' ? 0.25 : 0.75);
            const y = start.top + start.height / 2;
            const pointer = (type, clientX, clientY, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, isPrimary: true, clientX, clientY }));
            pointer('pointerdown', start.left + 2, y, grip);
            pointer('pointermove', start.left + 12, y);
            pointer('pointermove', x, y);
            const mid = {
                ghost: document.querySelector('.fi-ta-column-drag-ghost')?.isConnected ?? false,
                shifted: document.querySelector(`%s`).style.transform !== '',
                dragging: grip.closest('th').classList.contains('fi-ta-column-dragging'),
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
        ->assertPresent(columnHeader('created_at').' .fi-ta-column-handle')
        ->assertScript(headerOrder().'.indexOf('.json_encode($last).')', 1)
        ->assertNoJavaScriptErrors();
});

it('lifts the header and slides the other columns aside mid-drag', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $feedback = $page->script(dragHeader('created_at', 'accountOwner.name', finish: 'escape'));

    expect($feedback)->toBe(['ghost' => true, 'shifted' => true, 'dragging' => true]);
    $page->assertScript(dragLeftovers(), false);
    $page->assertNoJavaScriptErrors();
});

it('never drops before the title column', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(dragHeader('created_at', 'name'));

    waitUntil($page, headerOrder().'.indexOf("created_at") === 1')
        ->assertScript(headerOrder().'[0]', 'name')
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
            const grip = document.querySelector(`%s .fi-ta-column-handle`);
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
            const grip = document.querySelector(`%s .fi-ta-column-handle`);
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
        $page->click(columnMenuItem('Hide column'))->assertMissing(columnHeader($column));
    }

    $page->keys(columnHeader('created_at').' .fi-ta-column-handle', 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', ['ArrowDown', 'Enter']);
    waitUntil($page, headerOrder().'.join() === "name"');
    waitUntil($page, '('.focusedColumn().') === "name"')
        ->assertNoJavaScriptErrors();
});

function pinFromMenu(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    $page->script(openColumnMenu($column));
    $page->click(columnMenuItem('Pin to left'));

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
        ->assertScript(headerOrder().'.slice(0, 2)', ['name', 'created_at'])
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('name')), '1')
        ->wait(1)
        ->refresh()
        ->assertPresent(columnHeader('created_at').' .fi-ta-column-handle')
        ->assertScript(headerOrder().'.slice(0, 2)', ['name', 'created_at'])
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('created_at')), '1');

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertScript(visibleMenuItems(), ['Unpin', 'Hide column'])
        ->assertNoJavaScriptErrors();
});

it('locks a pinned column and unpins the title column with the last one', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at')
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('name')), '1')
        ->assertMissing(columnHeader('name').' .fi-ta-column-handle');

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, visibleMenuItems().'.join() === "Unpin,Hide column"');
    $page->click(columnMenuItem('Unpin'));
    waitUntil($page, sprintf('document.querySelector(`%s`).dataset.columnPinned === "0"', columnHeader('name')))
        ->assertScript(sprintf('document.querySelector(`%s`).dataset.columnPinned', columnHeader('created_at')), '0')
        ->assertMissing(columnHeader('name').' .fi-ta-column-handle')
        ->assertNoJavaScriptErrors();
});

it('never drags a pinned column or drops another among the pins', function (): void {
    $page = pinFromMenu(companiesTable($this->user, $this->workspace->slug), 'created_at');
    $order = $page->script(headerOrder());

    expect($page->script(dragHeader('created_at', end($order))))->toBe(['ghost' => false, 'shifted' => false, 'dragging' => false]);

    $page->script(dragHeader(end($order), 'created_at'));
    waitUntil($page, headerOrder().'.indexOf('.json_encode(end($order)).') === 2')
        ->assertScript(headerOrder().'.slice(0, 2)', ['name', 'created_at'])
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
    $page = pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');
    $edges = leftEdges("document.querySelector(`th[x-data^=\"resizableColumn('name'\"]`)", firstCell('name'), firstCell('created_at'));
    $before = $page->script($edges);

    $page->script('(() => { const wrapper = document.querySelector(".fi-ta-content-ctn"); wrapper.scrollLeft = wrapper.scrollWidth; return true })()');

    waitUntil($page, 'document.querySelector(".fi-ta-content-ctn").scrollLeft > 300')
        ->wait(0.2)
        ->assertScript($edges, $before)
        ->assertScript('document.querySelector("table.fi-ta-table").hasAttribute("data-scrolled-x")', true)
        ->assertScript(sprintf('(() => { const cell = %s; const box = cell.getBoundingClientRect(); return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest("td") === cell })()', firstCell('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('moves the pinned columns after a pinned column that is resized', function (): void {
    $page = pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');
    $before = (int) $page->script('parseInt('.firstCell('created_at').'.style.left, 10)');

    $page->script(sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0, clientX: x, clientY: y }));
            document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: x + 60, clientY: y }));
            return true;
        })();
    JS, columnHeader('name')));

    $page->assertScript('parseInt('.firstCell('created_at').'.style.left, 10)', $before + 60);
    $page->script('document.dispatchEvent(new MouseEvent("mouseup", { bubbles: true }))');

    $page->wait(1)
        ->assertScript('parseInt('.firstCell('created_at').'.style.left, 10)', $before + 60)
        ->assertNoJavaScriptErrors();
});

it('leaves pins unstuck when they would cover most of a narrow table', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(640, 900)->wait(0.5);

    pinFromMenu(pinFromMenu($page, 'accountOwner.name'), 'creator.name')
        ->wait(0.3)
        ->assertScript('document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")', false)
        ->assertScript(firstCell('name').'.style.left', '')
        ->assertNoJavaScriptErrors();
});

it('keeps the handles of scrolled headers under the pinned headers', function (): void {
    $page = pinFromMenu(wideCompaniesTable($this->user, $this->workspace->slug), 'created_at');
    waitUntil($page, 'document.querySelector("table.fi-ta-table").classList.contains("fi-ta-has-pinned")');

    $covered = $page->script(sprintf(<<<'JS'
        (() => {
            const wrapper = document.querySelector('.fi-ta-content-ctn');
            const pinned = document.querySelector(`%s`).getBoundingClientRect();
            const handle = document.querySelector(`%s .fi-ta-column-handle`);
            wrapper.scrollLeft += handle.getBoundingClientRect().left - (pinned.left + pinned.width / 2);
            handle.style.opacity = '1';
            const box = handle.getBoundingClientRect();
            return document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)?.closest('th')?.dataset.columnPinned;
        })();
    JS, columnHeader('created_at'), columnHeader('accountOwner.name')));

    expect($covered)->toBe('1');
    $page->assertNoJavaScriptErrors();
});

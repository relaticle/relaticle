<?php

declare(strict_types=1);

use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Models\Company;
use App\Models\User;

mutates(HasColumnMenu::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('moves and hides a column from its handle menu and keeps it after a reload', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $index = array_search('creator.name', $order, true);

    $page->script(openColumnMenu('creator.name'));
    $page->click(columnMenuItem('moveRight'))
        ->assertScript(headerOrder().'.indexOf("creator.name")', $index + 1);

    $beforeHidden = $page->script('(() => { const order = '.headerOrder().'; const at = order.indexOf("accountOwner.name"); return order[at - 1] ?? order[at + 1]; })()');

    $page->script(openColumnMenu('accountOwner.name'));
    $page->click(columnMenuItem('hide'))
        ->assertMissing(columnHeader('accountOwner.name'));
    waitUntil($page, '('.focusedColumn().') === '.json_encode($beforeHidden))
        ->wait(1)
        ->refresh()
        ->assertVisible(columnHeader('creator.name'))
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertScript(headerOrder().'.indexOf("creator.name") > '.headerOrder().'.indexOf("created_at")', true)
        ->assertNoJavaScriptErrors();
});

it('opens the column menu from a header click and never sorts', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)
        ->assertPresent(columnHeader('name').' [data-column-menu-trigger]');
    $tableSort = 'Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort';

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', columnHeader('created_at')));

    waitUntil($page, menuItemFocused())
        ->assertScript(visibleMenuItems(), ['Sort ascending', 'Sort descending', 'Move left', 'Move right', 'Pin to left', 'Edit column label', 'Hide from view'])
        ->wait(0.5)
        ->assertScript($tableSort.' === null', true)
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-sort")', columnHeader('created_at')), 'none')
        ->assertScript(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn > .fi-icon`)?.checkVisibility() ?? false', columnHeader('created_at')), false)
        ->assertNoJavaScriptErrors();
});

it('sorts a column both ways from its menu and shows the sort in the bar', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $tableSort = 'Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort';

    $page->script(openColumnMenu('creator.name'));
    waitUntil($page, menuItemFocused())->click(columnMenuItem('sortAscending'));
    waitUntil($page, $tableSort.' === "creator.name:asc"');
    waitUntil($page, sprintf('document.querySelector(`%s`).getAttribute("aria-sort") === "ascending"', columnHeader('creator.name')))
        ->assertSeeIn('[data-filter-sort-label]', 'Created by');
    waitUntil($page, '('.focusedColumn().') === "creator.name"');

    $page->script(openColumnMenu('creator.name'));
    waitUntil($page, menuItemFocused())
        ->assertScript(checkedSort(), ['sortAscending'])
        ->click(columnMenuItem('sortDescending'));
    waitUntil($page, $tableSort.' === "creator.name:desc"')
        ->refresh()
        ->assertPresent(columnHeader('creator.name').' [data-column-menu-trigger]')
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-sort")', columnHeader('creator.name')), 'descending');

    $page->script(openColumnMenu('creator.name'));
    waitUntil($page, menuItemFocused())
        ->assertScript(checkedSort(), ['sortDescending']);

    $page->keys(':focus', 'Escape');
    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertScript(checkedSort(), [])
        ->assertNoJavaScriptErrors();
});

function checkedSort(): string
{
    return '[...document.querySelectorAll(".fi-ta-column-menu [role=menuitemradio]")].filter((item) => item.getAttribute("aria-checked") === "true" && item.querySelector(".fi-menu-check").checkVisibility()).map((item) => item.dataset.columnMenuItem)';
}

it('changes a sorted column in place and puts a new one first, keeping the other sorts', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $wire = 'Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire';
    $stored = "[{$wire}.tableSort, {$wire}.tableThenSorts].filter(Boolean).join()";

    $page->script("{$wire}.setFilterBarSorts([{ column: 'name', direction: 'asc' }, { column: 'created_at', direction: 'desc' }])");
    waitUntil($page, 'document.querySelector("[data-filter-sort-more]")?.textContent.trim() === "+1"');

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertScript(checkedSort(), ['sortDescending'])
        ->click(columnMenuItem('sortAscending'));
    waitUntil($page, $stored.' === "name:asc,created_at:asc"');

    $page->script(openColumnMenu('creator.name'));
    waitUntil($page, menuItemFocused())
        ->assertScript(checkedSort(), [])
        ->click(columnMenuItem('sortDescending'));
    waitUntil($page, $stored.' === "creator.name:desc,name:asc,created_at:asc"')
        ->assertNoJavaScriptErrors();
});

it('offers no sort in the menu of a column that cannot sort', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)
        ->assertPresent('th[data-column-sortable="0"] [data-column-menu-trigger]');

    $page->script('document.querySelector(`th[data-column-sortable="0"] [data-column-menu-trigger]`).click()');

    waitUntil($page, menuItemFocused())
        ->assertScript(visibleMenuItems().'.includes("Sort ascending") || '.visibleMenuItems().'.includes("Sort descending")', false)
        ->assertNoJavaScriptErrors();
});

it('opens the menu of every header from a click on its cell, sortable or not', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $columns = $page->script(headerOrder());

    foreach ($columns as $column) {
        $page->script(sprintf('document.querySelector(`%s`).click()', columnHeader($column)));
        waitUntil($page, menuItemFocused());
        $page->keys(':focus', 'Escape');
        waitUntil($page, '!document.querySelector(".fi-ta-column-menu") || document.querySelector(".fi-ta-column-menu").classList.contains("hidden")');
    }

    $page->assertNoJavaScriptErrors();
});

it('opens the menu from Enter or Space on a focused header and returns focus on Escape', function (string $key): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $trigger = columnHeader('created_at').' [data-column-menu-trigger]';

    $page->assertAttribute($trigger, 'aria-haspopup', 'menu')
        ->assertAttribute($trigger, 'aria-expanded', 'false')
        ->keys($trigger, $key);
    waitUntil($page, menuItemFocused())->assertAttribute($trigger, 'aria-expanded', 'true');
    $page->keys(':focus', 'Escape');
    waitUntil($page, sprintf('document.activeElement === document.querySelector(`%s`)', $trigger))
        ->assertScript('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort === null', true)
        ->assertNoJavaScriptErrors();
})->with(['Enter', 'Space']);

it('places the column menu below its header cell on the start edge', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())
        ->assertScript(sprintf('(() => { const th = document.querySelector(`%s`).getBoundingClientRect(); const menu = document.querySelector(".fi-ta-column-menu").getBoundingClientRect(); return Math.abs(menu.left - th.left) <= 1 && Math.abs(menu.top - th.bottom - 4) <= 1; })()', columnHeader('created_at')), true)
        ->assertNoJavaScriptErrors();
});

it('stays on the list when a column menu item is double-clicked', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused());

    $page->script(sprintf(<<<'JS'
        (() => {
            const item = document.querySelector(`%s`);
            const box = item.getBoundingClientRect();
            const point = { bubbles: true, cancelable: true, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            item.dispatchEvent(new MouseEvent('click', { ...point, detail: 1 }));
            requestAnimationFrame(() => document.elementFromPoint(point.clientX, point.clientY)?.dispatchEvent(new MouseEvent('click', { ...point, detail: 2 })));
            return true;
        })();
    JS, columnMenuItem('pin')));

    waitUntil($page, sprintf('document.querySelector(`%s`)?.dataset.columnPinned === "1"', columnHeader('created_at')))
        ->wait(0.5)
        ->assertPathIs("/app/{$this->workspace->slug}/companies")
        ->assertNoJavaScriptErrors();
});

it('disables the moves at the edges', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());

    $page->script(openColumnMenu($order[0]));
    waitUntil($page, menuItemFocused())
        ->assertAttribute(columnMenuItem('moveLeft'), 'aria-disabled', 'true')
        ->keys(':focus', 'Escape');

    $page->script(openColumnMenu(end($order)));
    waitUntil($page, menuItemFocused())
        ->assertAttribute(columnMenuItem('moveRight'), 'aria-disabled', 'true')
        ->assertNoJavaScriptErrors();
});

it('closes the menu when focus leaves it', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())->keys(':focus', 'Tab');

    waitUntil($page, visibleMenuItems().'.length === 0')->assertNoJavaScriptErrors();
});

it('jumps to the first and last menu item with Home and End', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $focusedItem = 'document.activeElement.dataset.columnMenuItem';

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())->keys(':focus', 'End');
    waitUntil($page, "{$focusedItem} === 'hide'")->keys(':focus', 'Home');

    waitUntil($page, "{$focusedItem} === 'sortAscending'")->assertNoJavaScriptErrors();
});

it('moves a column right past a hidden one', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('creator.name'));
    $page->click(columnMenuItem('hide'))
        ->assertMissing(columnHeader('creator.name'));

    $order = $page->script(headerOrder());
    $next = $order[array_search('accountOwner.name', $order, true) + 1];

    $page->script(openColumnMenu('accountOwner.name'));
    $page->click(columnMenuItem('moveRight'))
        ->assertScript(headerOrder().'.indexOf("accountOwner.name")', array_search($next, $order, true))
        ->assertNoJavaScriptErrors();
});

it('works the handle by keyboard alone and keeps focus on the moved column', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $index = array_search('creator.name', $page->script(headerOrder()), true);
    $positionIs = fn (int $position): string => headerOrder().'.indexOf("creator.name") === '.$position;
    $handleFocused = '(document.activeElement.hasAttribute("data-column-menu-trigger") && ('.focusedColumn().') === "creator.name")';
    $handle = columnHeader('creator.name').' [data-column-menu-trigger]';

    $page->keys($handle, 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', ['ArrowDown', 'ArrowDown', 'ArrowDown', 'Enter']);
    waitUntil($page, $positionIs($index + 1));
    waitUntil($page, $handleFocused);

    $page->keys($handle, 'ArrowLeft');
    waitUntil($page, $positionIs($index));
    waitUntil($page, $handleFocused);

    $page->keys($handle, 'Enter');
    waitUntil($page, menuItemFocused())->keys(':focus', 'Escape');
    waitUntil($page, $handleFocused)
        ->assertMissing(columnMenuItem('moveLeft'))
        ->assertNoJavaScriptErrors();
});

it('opens the menu on a plain header click but not after a drag that comes back to the header', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    expect($page->script(pressHeader('created_at', '.fi-ta-header-cell-sort-btn', 2)))->toBeFalse();
    waitUntil($page, menuItemFocused());
    $page->keys(':focus', 'Escape');
    waitUntil($page, 'document.querySelector(".fi-ta-column-menu").classList.contains("hidden")');

    expect($page->script(pressHeader('created_at', '.fi-ta-header-cell-sort-btn', 40)))->toBeTrue();
    $page->wait(0.5)
        ->assertScript('document.querySelector(".fi-ta-column-menu").classList.contains("hidden")', true)
        ->assertNoJavaScriptErrors();
});

it('reorders a column dragged from its header and leaves the sort alone', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());

    $page->script(sprintf(<<<'JS'
        (() => {
            const th = document.querySelector(`%s`);
            const next = document.querySelector(`%s`).getBoundingClientRect();
            const box = th.querySelector('.fi-ta-header-cell-sort-btn').getBoundingClientRect();
            const y = box.top + box.height / 2;
            const pointer = (type, clientX, on = document) => on.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, pointerType: 'mouse', clientX, clientY: y }));
            pointer('pointerdown', box.left + 4, th.querySelector('.fi-ta-header-cell-sort-btn'));
            pointer('pointermove', box.left + 30);
            pointer('pointermove', next.right + 20);
            pointer('pointerup', next.right + 20);
        })();
    JS, columnHeader('creator.name'), columnHeader($order[array_search('creator.name', $order, true) + 1] ?? 'creator.name')));

    waitUntil($page, headerOrder().'.indexOf("creator.name") !== '.array_search('creator.name', $order, true))
        ->assertScript('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort === null', true)
        ->assertNoJavaScriptErrors();
});

it('starts no drag from the resize grip or from a touch', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());

    expect($page->script(pressHeader('created_at', '.column-resize-handle-bar', 40)))->toBeFalse()
        ->and($page->script(pressHeader('created_at', '.fi-ta-resizable-label', 40, 'touch')))->toBeFalse();

    $page->wait(0.3)
        ->assertScript(headerOrder(), $order)
        ->assertNoJavaScriptErrors();
});

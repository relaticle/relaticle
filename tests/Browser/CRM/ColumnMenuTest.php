<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\ResizableColumns;
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
    return visitAs($user, "/app/{$slug}/companies", 1440, 900)
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]');
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
    return sprintf('document.querySelector(`%s [data-column-menu-trigger]`).click()', columnHeader($column));
}

function columnMenuItem(string $key): string
{
    return ".fi-ta-column-menu [data-column-menu-item=\"{$key}\"]";
}

function visibleMenuItems(): string
{
    return '[...document.querySelectorAll(".fi-ta-column-menu [role^=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())';
}

function menuItemFocused(): string
{
    return 'document.activeElement.getAttribute("role")?.startsWith("menuitem") === true';
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

function pressHeader(string $column, string $on, int $moveBy, string $pointerType = 'mouse'): string
{
    return sprintf(<<<'JS'
        (() => {
            const target = document.querySelector(`%s %s`);
            const box = target.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const pointer = (type, clientX, on = document) => on.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, pointerType: %s, clientX, clientY: y }));
            pointer('pointerdown', x, target);
            pointer('pointermove', x + %d);
            const dragging = !!document.querySelector('.fi-ta-column-drag-ghost');
            pointer('pointermove', x);
            pointer('pointerup', x);
            target.click();
            return dragging;
        })();
    JS, columnHeader($column), $on, json_encode($pointerType), $moveBy);
}

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

function openViewSettings(AwaitableWebpage $page): AwaitableWebpage
{
    $page->click('.fi-view-settings-trigger');

    return $page->assertVisible('.fi-view-settings-panel');
}

function settingsRow(string $column, string $control = ''): string
{
    return trim(sprintf('.fi-view-settings-row[data-column="%s"] %s', $column, $control));
}

function settingsMenuItem(string $key): string
{
    return ".fi-view-settings-row-menu [data-view-settings-menu-item=\"{$key}\"]";
}

function rowMenuOpen(): string
{
    return '(() => { const menu = document.querySelector(".fi-view-settings-row-menu"); return menu.checkVisibility() && getComputedStyle(menu).visibility === "visible"; })()';
}

function openRowMenu(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    $page->click(settingsRow($column, '[data-view-settings-menu]'));

    return waitUntil($page, rowMenuOpen().' && document.activeElement.matches(".fi-view-settings-row-menu [role=menuitem]")');
}

function removeFromSettings(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    return $page->click(settingsRow($column, '[data-view-settings-menu]'))
        ->click(settingsMenuItem('remove'));
}

function settingsOrder(): string
{
    return '[...document.querySelectorAll(".fi-view-settings-row")].map((row) => row.dataset.column)';
}

it('keeps the view settings order in step with the headers', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    $page->click(columnMenuItem('moveLeft'));
    $page->wait(1);
    openViewSettings($page);

    $headers = $page->script(headerOrder());

    expect($page->script(settingsOrder()))->toBe($headers);
    $page->assertNoJavaScriptErrors();
});

it('hides a column from view settings and shows it again from the add attribute panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    removeFromSettings($page, 'accountOwner.name')
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertMissing(settingsRow('accountOwner.name'))
        ->assertVisible('.fi-view-settings-panel');

    $page->click('[data-view-settings-add]')
        ->click('[aria-label="Show Account Owner"]')
        ->assertVisible(columnHeader('accountOwner.name'));

    waitUntil($page, headerOrder().'.at(-1) === "accountOwner.name"')
        ->assertScript(settingsOrder().'.at(-1)', 'accountOwner.name')
        ->assertNoJavaScriptErrors();
});

it('reorders a column by dragging it in view settings and keeps the order after a reload', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(headerOrder());
    $moved = $order[3];
    $target = $order[1];

    $page->drag(settingsRow($moved, '.fi-view-settings-grip'), settingsRow($target, '.fi-view-settings-grip'));

    waitUntil($page, headerOrder().'.indexOf('.json_encode($moved).') < '.headerOrder().'.indexOf('.json_encode($target).')')
        ->assertScript(settingsOrder(), $page->script(headerOrder()))
        ->wait(1)
        ->refresh()
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]')
        ->assertScript(headerOrder().'.indexOf('.json_encode($moved).') < '.headerOrder().'.indexOf('.json_encode($target).')', true)
        ->assertNoJavaScriptErrors();
});

function companiesTableSort(): string
{
    return 'Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort';
}

function sortedCompaniesTable(object $test): AwaitableWebpage
{
    $page = openViewSettings(companiesTable($test->user, $test->workspace->slug));
    $page->script('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.sortTable("name", "asc")');
    waitUntil($page, '('.companiesTableSort().') === "name:asc"');
    removeFromSettings($page, 'accountOwner.name')->assertMissing(columnHeader('accountOwner.name'));

    return $page;
}

it('reaches every row menu from the keyboard and returns focus to the trigger on Escape', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->keys('.fi-view-settings-trigger', 'Enter')->assertVisible('.fi-view-settings-panel');
    $page->keys('.fi-view-settings-trigger', 'Tab');

    foreach (range(1, 20) as $attempt) {
        if ($page->script('document.activeElement.matches("[data-view-settings-menu]") && !document.activeElement.closest("[data-column-locked]")') === true) {
            break;
        }

        $page->keys(':focus', 'Tab');
    }

    $column = $page->script('document.activeElement.closest("[data-column]").dataset.column');

    expect($column)->toBeString();

    $page->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)')
        ->assertVisible('.fi-view-settings-row-menu');

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="remove"]`)');

    $page->keys(':focus', 'Escape');
    waitUntil($page, sprintf('document.activeElement === document.querySelector(`%s`)', settingsRow($column, '[data-view-settings-menu]')))
        ->assertMissing('.fi-view-settings-row-menu')
        ->assertVisible('.fi-view-settings-panel')
        ->assertPresent(columnHeader($column));

    $page->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)');
    $page->keys(':focus', 'ArrowDown')->keys(':focus', 'Enter');
    waitUntil($page, sprintf('document.querySelector(`%s`) === null', columnHeader($column)));

    $page->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertScript('document.querySelector(".fi-view-settings-panel").checkVisibility()', false)
        ->assertNoJavaScriptErrors();
});

it('focuses the first row when view settings opens from the keyboard and steps with the arrow keys', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->keys('.fi-view-settings-trigger', 'Enter')->assertVisible('.fi-view-settings-panel');
    $first = 'document.activeElement === document.querySelector(".fi-view-settings-row [data-view-settings-menu]")';
    waitUntil($page, $first);

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-menu]") && document.activeElement !== document.querySelector(".fi-view-settings-row [data-view-settings-menu]")');

    $page->keys(':focus', 'ArrowUp');
    waitUntil($page, $first);

    $page->keys(':focus', 'ArrowUp');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")');

    $page->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertNoJavaScriptErrors();
});

it('reaches add attribute from the keyboard', function (): void {
    $page = sortedCompaniesTable($this);
    $page->keys('.fi-view-settings-trigger', 'Tab');

    foreach (range(1, 40) as $attempt) {
        if ($page->script('document.activeElement.matches("[data-view-settings-add]")') === true) {
            break;
        }

        $page->keys(':focus', 'Tab');
    }

    $page->assertScript('document.activeElement.matches("[data-view-settings-add]")', true)
        ->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-search]")');
    $page->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")');

    $page->assertNoJavaScriptErrors();
});

it('offers only a label change in the title column menu of view settings', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click(settingsRow('name', '[data-view-settings-menu]'));
    waitUntil($page, 'document.querySelector(".fi-view-settings-row-menu").checkVisibility()')
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row-menu [role=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())', ['Edit column label'])
        ->assertNoJavaScriptErrors();
});

it('draws every view settings row with an icon and a menu button and no eye button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->assertScript('[...document.querySelectorAll(".fi-view-settings-row")].every((row) => row.querySelector(".fi-view-settings-label svg") && row.querySelector("[data-view-settings-menu]"))', true)
        ->assertMissing('[data-view-settings-toggle]')
        ->assertNoJavaScriptErrors();
});

it('opens the row menu beside its button, aligned to its start edge and inside the viewport', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $trigger = settingsRow('created_at', '[data-view-settings-menu]');

    $page->click($trigger);
    waitUntil($page, 'document.querySelector(".fi-view-settings-row-menu").checkVisibility() && getComputedStyle(document.querySelector(".fi-view-settings-row-menu")).visibility === "visible"')
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row-menu [role=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())', ['Edit column label', 'Hide from view'])
        ->assertScript(sprintf('(() => { const t = document.querySelector(`%s`).getBoundingClientRect(); const m = document.querySelector(".fi-view-settings-row-menu").getBoundingClientRect(); return Math.abs(m.left - t.left) <= 1 && m.top >= t.bottom && m.right <= document.documentElement.clientWidth && m.bottom <= document.documentElement.clientHeight; })()', $trigger), true)
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-expanded")', $trigger), 'true')
        ->assertNoJavaScriptErrors();
});

it('removes a column from its row menu and offers it again in the add attribute panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    removeFromSettings($page, 'accountOwner.name')
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertMissing(settingsRow('accountOwner.name'))
        ->assertVisible('.fi-view-settings-panel');

    $page->click('[data-view-settings-add]')
        ->assertVisible('[aria-label="Show Account Owner"]')
        ->assertNoJavaScriptErrors();
});

it('changes a column label from its row menu and shows it in the row and the header', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click(settingsRow('created_at', '[data-view-settings-menu]'))
        ->click(settingsMenuItem('label'))
        ->assertVisible('.fi-modal-window input')
        ->type('.fi-modal-window input', 'Added on')
        ->click('.fi-modal-window button[type="submit"]');

    waitUntil($page, sprintf('document.querySelector(`%s .fi-ta-header-cell-field`).textContent.trim() === "Added on"', columnHeader('created_at')))
        ->assertScript(sprintf('document.querySelector(`%s .fi-view-settings-label`).textContent.trim()', settingsRow('created_at')), 'Added on')
        ->assertNoJavaScriptErrors();
});

it('creates an attribute from the add panel and shows it as a column, with focus back on view settings', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->assertVisible('[data-view-settings-create]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window input')
        ->type('.fi-modal-window input', 'Lead score')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->assertScript('document.querySelectorAll(".fi-modal-window .fi-select-input-option .lucide").length > 10', true)
        ->click('.fi-modal-window .fi-select-input-option:nth-child(2)');

    $page->click('.fi-modal-window button[type="submit"]');

    waitUntil($page, 'document.querySelector(`th[x-data^="resizableColumn(\'custom_fields.lead_score\'"]`) !== null')
        ->assertScript(headerOrder().'.at(-1)', 'custom_fields.lead_score')
        ->assertScript('document.activeElement.matches(".fi-view-settings-trigger")', true)
        ->assertNoJavaScriptErrors();

    openViewSettings($page)
        ->assertScript(settingsOrder().'.at(-1)', 'custom_fields.lead_score')
        ->assertNoJavaScriptErrors();
});

it('shows the row menu button on every row without a hover, also on a phone', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    openViewSettings($page)
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row [data-view-settings-menu]")].every((button) => button.checkVisibility({ opacityProperty: true }) && Number(getComputedStyle(button).opacity) > 0)', true)
        ->assertNoJavaScriptErrors();
});

it('darkens the row menu button and fills the row on hover', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $button = settingsRow('created_at', '[data-view-settings-menu]');
    $color = sprintf('getComputedStyle(document.querySelector(`%s`).querySelector(".fi-icon")).color', $button);
    $resting = $page->script($color);

    $page->hover(settingsRow('created_at', '.fi-view-settings-label'))
        ->assertScript(sprintf('getComputedStyle(document.querySelector(`%s`)).backgroundColor', settingsRow('created_at')), $page->script('(() => { const probe = document.createElement("div"); probe.style.backgroundColor = "var(--gray-50)"; document.body.append(probe); const color = getComputedStyle(probe).backgroundColor; probe.remove(); return color; })()'));

    expect($page->script($color))->not->toBe($resting);
});

it('draws the add panel items as tall as the view settings rows', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $rowHeight = $page->script('Math.round(document.querySelector(".fi-view-settings-row").getBoundingClientRect().height)');
    $page->click(settingsRow('created_at', '[data-view-settings-menu]'))
        ->click(settingsMenuItem('remove'))
        ->click('[data-view-settings-add]')
        ->assertVisible('[data-view-settings-add-item][data-attribute="created_at"]');

    $page->assertScript('Math.round(document.querySelector("[data-view-settings-add-item][data-attribute=created_at]").getBoundingClientRect().height)', $rowHeight)
        ->assertNoJavaScriptErrors();
});

it('clears the add panel search with Escape', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->type('[data-view-settings-search]', 'zzz')
        ->keys('[data-view-settings-search]', 'Escape')
        ->assertScript('document.querySelector("[data-view-settings-search]").value', '')
        ->assertNoJavaScriptErrors();
});

it('offers the required type of a new attribute with no clear button and names the option input', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window .fi-select-input-btn')
        ->assertMissing('.fi-modal-window .fi-select-input-value-remove-btn')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->click('.fi-modal-window .fi-select-input-option[data-value="select"]')
        ->assertVisible('.fi-modal-window .fi-fo-repeater input')
        ->assertScript('document.querySelector(".fi-modal-window .fi-fo-repeater input").placeholder', 'Option name')
        ->assertNoJavaScriptErrors();
});

it('opens the attribute type list below its button on a phone', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    openViewSettings($page)->click('[data-view-settings-add]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window .fi-select-input-btn')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->assertVisible('.fi-modal-window .fi-select-input-option[data-value="text"]')
        ->assertScript('document.querySelector(".fi-modal-window .fi-select-input-option").closest("ul").getBoundingClientRect().top >= document.querySelector(".fi-modal-window .fi-select-input-btn").getBoundingClientRect().bottom', true)
        ->assertNoJavaScriptErrors();
});

it('never starts a drag from the row menu button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    $page->drag(settingsRow($order[3], '[data-view-settings-menu]'), settingsRow($order[1], '.fi-view-settings-grip'));
    $page->wait(1)->assertScript(settingsOrder(), $order)
        ->assertScript(headerOrder(), $order)
        ->assertNoJavaScriptErrors();
});

it('opens view settings as a sheet on a phone without overflowing the header', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('document.querySelector(".fi-header").scrollWidth <= document.querySelector(".fi-header").clientWidth', true)
        ->assertScript('document.querySelector(".fi-view-settings-trigger").getBoundingClientRect().right <= document.documentElement.clientWidth', true);

    openViewSettings($page)
        ->assertScript('getComputedStyle(document.querySelector(".fi-view-settings-panel").closest(".fi-dropdown-panel")).position', 'fixed')
        ->assertScript('Math.round(document.querySelector(".fi-view-settings-panel").closest(".fi-dropdown-panel").getBoundingClientRect().bottom) === window.innerHeight', true)
        ->assertNoJavaScriptErrors();
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

it('renames a column from its handle menu and keeps the name after a reload', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $labelOf = fn (string $column): string => sprintf('document.querySelector(`%s .fi-ta-header-cell-field`).textContent.trim()', columnHeader($column));

    $page->script(openColumnMenu('created_at'));
    $page->click(columnMenuItem('editLabel'))
        ->assertVisible('.fi-modal-window input')
        ->type('.fi-modal-window input', 'Added on')
        ->click('.fi-modal-window button[type="submit"]');

    waitUntil($page, '('.$labelOf('created_at').') === "Added on"')
        ->wait(1)
        ->refresh()
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]')
        ->assertScript($labelOf('created_at'), 'Added on')
        ->assertNoJavaScriptErrors();
});

it('shows the move cursor on a movable header and keeps the pointer on its controls', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $cursor = fn (string $selector): string => sprintf('getComputedStyle(document.querySelector(`%s`)).cursor', $selector);

    $page->assertScript($cursor(columnHeader('created_at')), 'move')
        ->assertScript($cursor(columnHeader('created_at').' .fi-ta-header-cell-sort-btn'), 'pointer')
        ->assertScript($cursor(columnHeader('created_at').' [data-column-menu-trigger]'), 'pointer')
        ->assertScript($cursor(columnHeader('name')), 'move')
        ->assertMissing('.fi-ta-column-grip')
        ->assertNoJavaScriptErrors();
});

it('keeps the menu of the first column inside the table, clear of the sidebar', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['name' => ResizableColumns::MIN_WIDTH]),
    ]);
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('name'));
    waitUntil($page, menuItemFocused())
        ->assertScript('document.querySelector(".fi-ta-column-menu").getBoundingClientRect().left >= document.querySelector(".fi-ta").getBoundingClientRect().left', true)
        ->assertNoJavaScriptErrors();
});

it('lines up the select-all checkbox with the row checkboxes', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->assertScript('Math.round(document.querySelector("thead th.fi-ta-selection-cell .fi-ta-page-checkbox").getBoundingClientRect().left) === Math.round(document.querySelector("tbody td.fi-ta-selection-cell .fi-ta-record-checkbox").getBoundingClientRect().left)', true)
        ->assertNoJavaScriptErrors();
});

it('keeps the column menu on screen when its column sits near the left edge', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['name' => ResizableColumns::MIN_WIDTH, 'accountOwner.name' => ResizableColumns::MIN_WIDTH]),
    ]);
    $page = companiesTable($this->user, $this->workspace->slug)->resize(800, 900)->wait(0.5);

    $page->script(openColumnMenu('accountOwner.name'));

    waitUntil($page, menuItemFocused())
        ->assertScript('document.querySelector(".fi-ta-column-menu").getBoundingClientRect().left >= 8', true)
        ->assertScript('document.querySelector(".fi-ta-column-menu").getBoundingClientRect().right <= window.innerWidth - 8', true)
        ->assertNoJavaScriptErrors();
});

it('starts every header control collapsed and named after its column, and hides the resize handles from assistive tech', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $buttons = '[...document.querySelectorAll(`th[x-data^="resizableColumn"] [data-column-menu-trigger]`)]';

    $page->assertScript("{$buttons}.length > 3 && {$buttons}.every((button) => button.getAttribute(\"aria-expanded\") === \"false\")", true)
        ->assertScript("{$buttons}.every((button) => button.getAttribute(\"aria-haspopup\") === \"menu\" && !button.hasAttribute(\"aria-label\"))", true)
        ->assertScript(sprintf('document.querySelector(`%s [data-column-menu-trigger]`).textContent.trim()', columnHeader('name')), 'Company')
        ->assertScript("new Set({$buttons}.map((button) => button.textContent.trim())).size === {$buttons}.length", true)
        ->assertScript('[...document.querySelectorAll(`th[x-data^="resizableColumn"] .column-resize-handle-bar`)].every((handle) => handle.getAttribute("aria-hidden") === "true")', true)
        ->assertNoJavaScriptErrors();
});

function columnMenuPlacement(AwaitableWebpage $page, string $column): array
{
    $page->script(openColumnMenu($column));
    waitUntil($page, menuItemFocused());

    return $page->script(sprintf(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-ta-column-menu').getBoundingClientRect();
            const trigger = document.querySelector(`%s [data-column-menu-trigger]`).getBoundingClientRect();
            return { panelTop: panel.top, panelBottom: panel.bottom, triggerTop: trigger.top, viewport: document.documentElement.clientHeight };
        })()
    JS, columnHeader($column)));
}

it('opens the column menu above its button when only the space above fits it', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(1280, 600)->wait(0.5);
    $page->script('document.querySelector(".fi-ta-ctn").style.marginTop = "300px"');

    $placement = columnMenuPlacement($page, 'created_at');

    expect($placement['panelTop'])->toBeGreaterThanOrEqual(0)
        ->and($placement['panelBottom'])->toBeLessThanOrEqual($placement['triggerTop']);
    $page->assertNoJavaScriptErrors();
});

it('keeps the column menu inside a viewport too short for either side of its button', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(1280, 340)->wait(0.5);

    $placement = columnMenuPlacement($page, 'created_at');

    expect($placement['panelTop'])->toBeGreaterThanOrEqual(0)
        ->and($placement['panelBottom'])->toBeLessThanOrEqual($placement['viewport']);
    $page->assertNoJavaScriptErrors();
});

it('outlines the header control when the keyboard focuses it', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(sprintf('document.querySelector(`%s [data-column-menu-trigger]`).focus()', columnHeader('created_at')));

    $page->assertScript('document.activeElement.matches(":focus-visible") && getComputedStyle(document.activeElement).outlineStyle !== "none" && parseFloat(getComputedStyle(document.activeElement).outlineWidth) >= 2', true)
        ->assertNoJavaScriptErrors();
});

function openFilterPopover(AwaitableWebpage $page): AwaitableWebpage
{
    return $page->click('[data-filter-add]')
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility() === true', true);
}

function filterPopoverOpen(): string
{
    return 'document.querySelector("[data-pop][data-open=true]")?.checkVisibility() === true';
}

it('closes an open filter popover when a column header is clicked', function (): void {
    $page = openFilterPopover(companiesTable($this->user, $this->workspace->slug));

    $page->click(columnHeader('created_at').' .fi-ta-resizable-label');

    waitUntil($page, menuItemFocused())
        ->assertScript(filterPopoverOpen(), false)
        ->assertNoJavaScriptErrors();
});

it('closes an open filter popover when a header click moves no focus', function (): void {
    $page = openFilterPopover(companiesTable($this->user, $this->workspace->slug));

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', columnHeader('created_at')));

    waitUntil($page, menuItemFocused())
        ->assertScript(filterPopoverOpen(), false)
        ->assertNoJavaScriptErrors();
});

it('switches the menu to the header clicked next', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused());
    $page->click(columnHeader('name').' .fi-ta-header-cell-sort-btn');

    waitUntil($page, 'document.querySelector(`'.columnHeader('name').' [data-column-menu-trigger]`).getAttribute("aria-expanded") === "true"')
        ->assertScript('document.querySelector(`'.columnHeader('created_at').' [data-column-menu-trigger]`).getAttribute("aria-expanded")', 'false')
        ->assertScript(visibleMenuItems().'.length > 0', true)
        ->assertNoJavaScriptErrors();
});

it('closes the menu of one column when focus moves to the header of another', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused());
    $page->script(sprintf('document.querySelector(`%s [data-column-menu-trigger]`).focus()', columnHeader('name')));

    waitUntil($page, visibleMenuItems().'.length === 0')
        ->assertScript('document.querySelector(`'.columnHeader('created_at').' [data-column-menu-trigger]`).getAttribute("aria-expanded")', 'false')
        ->assertNoJavaScriptErrors();
});

it('closes the menu when another header starts a drag', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused());

    expect($page->script(pressHeader('creator.name', '.fi-ta-header-cell-sort-btn', 40)))->toBeTrue();

    waitUntil($page, visibleMenuItems().'.length === 0')->assertNoJavaScriptErrors();
});

it('does not open the menu after a resize that ends on the header at the minimum width', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['created_at' => ResizableColumns::MIN_WIDTH]),
    ]);
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->drag(columnHeader('created_at').' .column-resize-handle-bar', columnHeader('created_at').' .fi-ta-resizable-label')
        ->wait(0.5)
        ->assertScript(visibleMenuItems().'.length === 0', true)
        ->assertScript(sprintf('document.querySelector(`%s [data-column-menu-trigger]`).getAttribute("aria-expanded")', columnHeader('created_at')), 'false')
        ->assertNoJavaScriptErrors();
});

it('opens the menu from a header click while the table is loading', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).setAttribute("aria-disabled", "true")', columnHeader('created_at')));

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', columnHeader('created_at')));

    waitUntil($page, menuItemFocused())->assertNoJavaScriptErrors();
});

it('moves a column by the neighbours it has when the item is chosen, not when the menu opened', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $order = $page->script(headerOrder());
    $index = array_search('creator.name', $order, true);

    $page->script(openColumnMenu('creator.name'));
    waitUntil($page, menuItemFocused());
    $page->script(sprintf('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.hideTableColumn(%s)', json_encode($order[$index + 1])));
    $page->assertMissing(columnHeader($order[$index + 1]));
    $page->click(columnMenuItem('moveRight'));

    waitUntil($page, headerOrder().'.indexOf("creator.name") === '.($index + 1))->assertNoJavaScriptErrors();
});

it('closes the row menu when the list scrolls or the window resizes', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(1440, 360);
    openViewSettings($page);
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[1]);
    $page->script('document.querySelector(".fi-view-settings-scroll").scrollTop = 40');
    waitUntil($page, '!('.rowMenuOpen().')');

    openRowMenu($page, $order[1])->resize(1300, 360);
    waitUntil($page, '!('.rowMenuOpen().')')->assertNoJavaScriptErrors();
});

it('closes the row menu on Tab and Shift+Tab and returns focus to its button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $trigger = settingsRow('created_at', '[data-view-settings-menu]');
    $focusedTrigger = sprintf('document.activeElement === document.querySelector(`%s`)', $trigger);

    foreach (['Tab', 'Shift+Tab'] as $key) {
        openRowMenu($page, 'created_at')->keys(':focus', $key);
        waitUntil($page, '!('.rowMenuOpen().') && '.$focusedTrigger);
    }

    $page->assertVisible('.fi-view-settings-panel')->assertNoJavaScriptErrors();
});

it('moves through the row menu with Home and End', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->keys(':focus', 'End');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="remove"]`)');
    $page->keys(':focus', 'Home');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)')->assertNoJavaScriptErrors();
});

it('keeps focus in the table after the label modal closes', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->click(settingsMenuItem('label'))->assertVisible('.fi-modal-window input');
    $page->keys('.fi-modal-window input', 'Escape');

    waitUntil($page, 'document.activeElement.matches(".fi-view-settings-trigger, .fi-view-settings-trigger *")')->assertNoJavaScriptErrors();
});

it('returns focus to the column header after its label modal closes or saves', function (string $key): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())->click(columnMenuItem('editLabel'))->assertVisible('.fi-modal-window input');
    $page->keys('.fi-modal-window input', $key);

    waitUntil($page, sprintf('!document.querySelector(".fi-modal-open") && document.activeElement === document.querySelector(`%s [data-column-menu-trigger]`)', columnHeader('created_at')))->assertNoJavaScriptErrors();
})->with(['Escape', 'Enter']);

it('closes the row menu when a grip is dragged', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[1])->drag(settingsRow($order[3], '.fi-view-settings-grip'), settingsRow($order[2], '.fi-view-settings-grip'));

    waitUntil($page, '!('.rowMenuOpen().')')->assertNoJavaScriptErrors();
});

it('keeps the row of the open menu highlighted and ties the button to the menu', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->hover('[data-view-settings-menu-item="label"]');

    $page->assertScript(sprintf('!document.querySelector(`%s`).matches(":hover") && getComputedStyle(document.querySelector(`%s`)).backgroundColor !== "rgba(0, 0, 0, 0)"', settingsRow('created_at'), settingsRow('created_at')), true)
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-controls") === document.querySelector(".fi-view-settings-row-menu").id', settingsRow('created_at', '[data-view-settings-menu]')), true)
        ->assertNoJavaScriptErrors();
});

it('focuses the neighbouring row button after Hide from view', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[2])->click(settingsMenuItem('remove'));

    waitUntil($page, sprintf('document.activeElement === document.querySelector(`%s`)', settingsRow($order[3], '[data-view-settings-menu]')))->assertNoJavaScriptErrors();
});

it('opens the row menu above its button near the bottom of the viewport', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug)->resize(1440, 400));
    $last = $page->script(settingsOrder().'.at(-1)');

    $page->script('document.querySelectorAll(".fi-view-settings-footer").forEach((footer) => footer.remove()); document.querySelector(".fi-view-settings-scroll").scrollTop = 10000');
    openRowMenu($page, $last);

    waitUntil($page, sprintf('(() => { const menu = document.querySelector(".fi-view-settings-row-menu").getBoundingClientRect(); const button = document.querySelector(`%s`).getBoundingClientRect(); return button.bottom + 4 + menu.height > document.documentElement.clientHeight - 8 && menu.bottom <= button.top && menu.top >= 0; })()', settingsRow($last, '[data-view-settings-menu]')))
        ->assertNoJavaScriptErrors();
});

function addPanelOpen(): string
{
    return 'document.querySelector("[data-view-settings-add-panel]").checkVisibility()';
}

function addItems(): string
{
    return '[...document.querySelectorAll("[data-view-settings-add-item]")].filter((item) => item.checkVisibility()).map((item) => item.dataset.attribute)';
}

function openAddPanel(AwaitableWebpage $page): AwaitableWebpage
{
    $page->click('[data-view-settings-add]');

    return waitUntil($page, addPanelOpen().' && document.activeElement.matches("[data-view-settings-search]")');
}

function openAddPanelWithoutFocus(AwaitableWebpage $page): AwaitableWebpage
{
    $page->click('[data-view-settings-add]');

    return waitUntil($page, addPanelOpen());
}

function noMatchNoteVisible(): string
{
    return '[...document.querySelectorAll("[data-view-settings-add-panel] .fi-view-settings-note")].some((note) => note.checkVisibility())';
}

function hideColumns(AwaitableWebpage $page, array $columns): AwaitableWebpage
{
    foreach ($columns as $column) {
        removeFromSettings($page, $column)->assertMissing(settingsRow($column));
    }

    return $page;
}

it('opens the add attribute panel in place of the list with a focused search and every hidden attribute', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $width = $page->script('document.querySelector(".fi-view-settings-panel").getBoundingClientRect().width');

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page)
        ->assertScript('document.activeElement.placeholder', 'Search company attributes…')
        ->assertScript('document.querySelector("[data-view-settings-back]").checkVisibility()', true)
        ->assertScript('document.querySelector(".fi-view-settings-list").checkVisibility()', false)
        ->assertScript('document.querySelector(".fi-view-settings-panel").getBoundingClientRect().width', $width)
        ->assertScript('["accountOwner.name", "created_at"].every((attribute) => '.addItems().'.includes(attribute))', true)
        ->assertScript('[...document.querySelectorAll("[data-view-settings-add-item]")].every((item) => item.querySelector(".fi-view-settings-label svg"))', true)
        ->assertScript('document.querySelector("[data-view-settings-add-item][data-attribute=name]")', null)
        ->assertScript(noMatchNoteVisible(), false);

    $page->type('[data-view-settings-search]', 'zzzz-no-match');

    waitUntil($page, noMatchNoteVisible())
        ->assertNoJavaScriptErrors();
});

it('narrows the add attribute list as the member types, in any case and anywhere in the label', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $label = $page->script('document.querySelector("[data-view-settings-add-item][data-attribute=\'created_at\']").dataset.text');
    $needle = strtoupper(substr($label, 1, 3));

    $page->type('[data-view-settings-search]', $needle);

    waitUntil($page, addItems().'.includes("created_at")')
        ->assertScript(addItems().'.every((attribute) => document.querySelector(`[data-attribute="${attribute}"]`).dataset.text.toLowerCase().includes('.json_encode(strtolower($needle)).'))', true)
        ->type('[data-view-settings-search]', 'zzzz-no-match');

    waitUntil($page, addItems().'.length === 0')
        ->assertSee('No attribute matches your search')
        ->assertNoJavaScriptErrors();
});

it('moves through the add attribute results with the arrow keys and picks with Enter', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $items = $page->script(addItems());

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, sprintf('document.activeElement.dataset.attribute === %s', json_encode($items[0])));
    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, sprintf('document.activeElement.dataset.attribute === %s', json_encode($items[1])));
    $page->keys(':focus', 'ArrowUp')->keys(':focus', 'ArrowUp');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-search]")');
    $page->keys(':focus', 'ArrowDown')->keys(':focus', 'ArrowDown')->keys(':focus', 'Enter');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($items[1])))
        ->assertMissing(columnHeader($items[0]))
        ->assertNoJavaScriptErrors();
});

it('picks the first match with Enter from the search input and keeps the panel open with the search', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $first = $page->script(addItems())[0];
    $text = $page->script(sprintf('document.querySelector(`[data-attribute="%s"]`).dataset.text', $first));
    $term = substr($text, 0, 2);

    $page->type('[data-view-settings-search]', $term)->keys('[data-view-settings-search]', 'Enter');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($first)))
        ->assertScript(addPanelOpen(), true)
        ->assertScript('document.querySelector("[data-view-settings-search]").value', $term)
        ->assertScript('document.activeElement.matches("[data-view-settings-search]")', true)
        ->assertScript(addItems().'.includes('.json_encode($first).')', false);

    waitUntil($page, headerOrder().'.at(-1) === '.json_encode($first))
        ->assertNoJavaScriptErrors();
});

it('shows the picked attribute at the end of the list when the member goes back', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page)->click('[data-view-settings-add-item][data-attribute="accountOwner.name"]');

    waitUntil($page, headerOrder().'.at(-1) === "accountOwner.name"')
        ->assertScript(addPanelOpen(), true)
        ->assertScript(addItems().'.includes("created_at") && !'.addItems().'.includes("accountOwner.name")', true);

    $page->click('[data-view-settings-back]');

    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')
        ->assertScript(addPanelOpen(), false)
        ->assertScript(settingsOrder().'.at(-1)', 'accountOwner.name')
        ->assertNoJavaScriptErrors();
});

it('says every attribute is in the view when none is hidden', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page);

    $headers = count($page->script(headerOrder()));

    for ($remaining = count($page->script(addItems())); $remaining > 0; $remaining--) {
        $page->keys('[data-view-settings-search]', 'Enter');
        waitUntil($page, headerOrder().'.length === '.++$headers);
    }

    $page->assertSee('Every attribute is already in this view')
        ->assertScript(addItems(), [])
        ->assertNoJavaScriptErrors();
});

it('goes back to the list on Escape and closes view settings on a second Escape', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')
        ->assertScript(addPanelOpen(), false)
        ->assertVisible('.fi-view-settings-panel')
        ->assertVisible('[data-view-settings-add]');

    $page->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertScript('document.querySelector(".fi-view-settings-panel").checkVisibility()', false)
        ->assertNoJavaScriptErrors();
});

it('reopens view settings on the list', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->type('[data-view-settings-search]', 'abc')->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")');

    $page->click('.fi-view-settings-trigger');

    waitUntil($page, 'document.querySelector(".fi-view-settings-list").checkVisibility()')
        ->assertScript(addPanelOpen(), false)
        ->assertNoJavaScriptErrors();
});

it('reopens view settings on the list after closing it from the add panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->type('[data-view-settings-search]', 'abc')->click('.fi-view-settings-trigger');
    waitUntil($page, 'document.querySelector(".fi-view-settings-panel").checkVisibility() === false');

    $page->click('.fi-view-settings-trigger');

    waitUntil($page, 'document.querySelector(".fi-view-settings-list").checkVisibility()')
        ->assertScript(addPanelOpen(), false)
        ->assertScript('document.querySelector("[data-view-settings-search]").value', '')
        ->assertNoJavaScriptErrors();
});

it('picks a column once however fast the member presses Enter', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $text = $page->script('document.querySelector("[data-view-settings-add-item][data-attribute=\'created_at\']").dataset.text');

    $page->type('[data-view-settings-search]', $text);
    waitUntil($page, addItems().'.length === 1');
    $page->script('(async () => { const input = document.querySelector("[data-view-settings-search]"); for (let i = 0; i < 2; i++) { input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })); await new Promise((resolve) => setTimeout(resolve, 15)); } })()');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader('created_at')));
    $page->wait(1.5)
        ->assertPresent(columnHeader('created_at'))
        ->assertScript(headerOrder().'.filter((column) => column === "created_at").length', 1)
        ->assertNoJavaScriptErrors();
});

it('ignores a second Enter while the first pick is still landing', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    waitUntil($page, addItems().'.length >= 2');
    $count = $page->script(addItems().'.length');
    $first = $page->script(addItems().'[0]');
    $second = $page->script(addItems().'[1]');

    $page->script('(async () => { const input = document.querySelector("[data-view-settings-search]"); for (let i = 0; i < 2; i++) { input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })); await new Promise((resolve) => setTimeout(resolve, 15)); } })()');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($first)));
    $page->wait(1.5)
        ->assertNotPresent(columnHeader($second))
        ->assertScript(addItems().'.length', $count - 1)
        ->assertNoJavaScriptErrors();
});

it('leaves the search unfocused on a phone, at 16px, and after a pick', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 700)->wait(0.5);

    openViewSettings($page);
    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanelWithoutFocus($page)->wait(0.5)
        ->assertScript('document.activeElement.matches("[data-view-settings-search]")', false)
        ->assertScript('getComputedStyle(document.querySelector("[data-view-settings-search]")).fontSize', '16px');

    $page->click('[data-view-settings-add-item][data-attribute="created_at"]');
    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader('created_at')));
    $page->wait(0.5)->assertScript('document.activeElement.matches("[data-view-settings-search]")', false)
        ->assertNoJavaScriptErrors();
});

it('scrolls the add attribute list on a phone and keeps the search on screen', function (int $width): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    openViewSettings($page);
    $columns = array_slice($page->script('[...document.querySelectorAll(".fi-view-settings-row:not([data-column-locked])")].map((row) => row.dataset.column)'), 0, 6);
    hideColumns($page, $columns);
    $page->resize($width, 460)->wait(0.5);

    if ($page->script('document.querySelector(".fi-view-settings-panel").checkVisibility()') !== true) {
        openViewSettings($page);
    }

    openAddPanelWithoutFocus($page)
        ->assertScript('(() => { const r = document.querySelector("[data-view-settings-add-panel]").getBoundingClientRect(); return r.left >= 0 && r.right <= document.documentElement.clientWidth && r.bottom <= window.innerHeight; })()', true)
        ->assertScript('(() => { const list = document.querySelector("[data-view-settings-add-panel] .fi-view-settings-scroll"); return list.scrollHeight > list.clientHeight; })()', true);

    $page->script('(() => { const list = document.querySelector("[data-view-settings-add-panel] .fi-view-settings-scroll"); list.scrollTop = list.scrollHeight; })()');

    $page->assertScript('(() => { const r = document.querySelector("[data-view-settings-search]").getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; })()', true)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors();
})->with([390, 360]);

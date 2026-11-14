<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\ResizableColumns;
use App\Models\Company;
use App\Models\TablePreference;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

mutates(HasColumnMenu::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
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

function openFilterPopover(AwaitableWebpage $page): AwaitableWebpage
{
    return $page->click('[data-filter-add]')
        ->assertScript('document.querySelector("[data-pop][data-open=true]")?.checkVisibility() === true', true);
}

function filterPopoverOpen(): string
{
    return 'document.querySelector("[data-pop][data-open=true]")?.checkVisibility() === true';
}

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

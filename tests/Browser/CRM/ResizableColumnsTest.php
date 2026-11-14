<?php

declare(strict_types=1);

use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\ResizableColumns;
use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;

mutates(HasResizableColumns::class, ResizableColumns::class);

function headerSelector(string $column): string
{
    return "th[x-data^=\"resizableColumn('{$column}'\"]";
}

function headerWidth(string $column): string
{
    return sprintf('(() => Math.round(document.querySelector(`%s`).getBoundingClientRect().width))()', headerSelector($column));
}

function dragColumnEdge(string $column, int $by, string $pointerType = 'mouse'): string
{
    return sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const pointer = (type, clientX, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, isPrimary: true, pointerType: %s, clientX, clientY: y }));
            pointer('pointerdown', x, handle);
            pointer('pointermove', x + %d);
            pointer('pointerup', x + %d);
            return true;
        })();
    JS, headerSelector($column), json_encode($pointerType), $by, $by);
}

function dragFeedbackMidDrag(string $column): string
{
    return sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerId: 1, isPrimary: true, clientX: x, clientY: y }));
            document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerId: 1, isPrimary: true, clientX: x + 40, clientY: y }));
            const guideline = document.querySelector('.column-resize-guideline');
            const feedback = {
                guideline: guideline !== null && getComputedStyle(guideline).display !== 'none',
                handleActive: document.querySelector(`%s .column-resize-handle-bar`).classList.contains('active'),
                widthReadout: [...document.body.querySelectorAll('*')].some((element) => element.children.length === 0 && /^\s*\d+\s*px\s*$/.test(element.textContent) && element.checkVisibility()),
            };
            document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1, isPrimary: true, clientX: x + 40, clientY: y }));
            return feedback.guideline && feedback.handleActive && ! feedback.widthReadout;
        })();
    JS, headerSelector($column), headerSelector($column));
}

function tableAreaBottomGap(): string
{
    return <<<'JS'
        (() => {
            const container = document.querySelector('.fi-ta-content-ctn');
            const pagination = document.querySelector('.fi-ta-ctn .fi-pagination');
            const lowest = Math.max(container.getBoundingClientRect().bottom, pagination ? pagination.getBoundingClientRect().bottom : 0);
            return Math.abs(Math.round(lowest - window.innerHeight)) <= 1;
        })();
    JS;
}

function pageScrollsVertically(): string
{
    return <<<'JS'
        (() => {
            const layout = document.querySelector('.fi-layout');
            return document.scrollingElement.scrollHeight > window.innerHeight + 1 || layout.scrollHeight > layout.clientHeight + 1;
        })();
    JS;
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
    People::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('resizes a column by dragging its edge and keeps the width after a reload', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $page->assertScript('document.querySelector(".column-resize-handle-bar").tabIndex', -1);

    $before = $page->script(headerWidth('created_at'));

    $page->script(dragColumnEdge('created_at', 120));
    $page->assertScript(headerWidth('created_at'), $before + 120)
        ->wait(1)
        ->refresh()
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar')
        ->assertScript(headerWidth('created_at'), $before + 120)
        ->assertNoJavaScriptErrors();
});

it('leaves no focus ring behind after a mouse resize', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $page->click(headerSelector('created_at').' .fi-ta-resizable-label')
        ->wait(0.5)
        ->keys(':focus', 'Escape')
        ->wait(0.3)
        ->drag(headerSelector('created_at').' .column-resize-handle-bar', headerSelector('updated_at'))
        ->wait(1)
        ->assertScript('document.querySelector(":focus-visible")', null)
        ->assertNoJavaScriptErrors();
});

it('shows the edge guideline but no pixel width while a column is resized', function (): void {
    visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar')
        ->assertScript(dragFeedbackMidDrag('name'), true)
        ->assertNoJavaScriptErrors();
});

it('resizes a column with a finger and shows its edge on a touch screen', function (): void {
    $page = loginViaBrowser($this->user, page: $this->visit('/app/login')->on()->iPadMini()->assertPathIs('/app/login'))
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertPresent(headerSelector('name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('name'));

    $page->assertScript(sprintf('getComputedStyle(document.querySelector(`%s .column-resize-handle-bar`), "::before").width !== "0px"', headerSelector('name')), true);
    $page->script(dragColumnEdge('name', 60, 'touch'));
    $page->assertScript(headerWidth('name'), $before + 60)
        ->assertNoJavaScriptErrors();
});

function doubleClickColumnEdge(string $column): string
{
    return sprintf(
        'document.querySelector(`%s .column-resize-handle-bar`).dispatchEvent(new MouseEvent("dblclick", { bubbles: true }))',
        headerSelector($column),
    );
}

function fittedColumnShowsEverything(string $column): string
{
    return sprintf(<<<'JS'
        (() => {
            const th = document.querySelector(`%s`);
            const rightEdge = (root) => {
                const range = document.createRange();
                range.selectNodeContents(root);
                return Math.max(...[...range.getClientRects()].map((box) => box.right));
            };
            const cellRight = th.getBoundingClientRect().right;
            const cells = [...document.querySelectorAll(`td.fi-ta-cell-${CSS.escape(th.dataset.resizableColumnId)}`)];
            return [
                rightEdge(th.querySelector('.fi-ta-header-cell-sort-btn')) <= cellRight,
                cells.every((td) => rightEdge(td) <= td.getBoundingClientRect().right),
            ];
        })()
    JS, headerSelector($column));
}

it('fits a column to its content on double-click', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(doubleClickColumnEdge('created_at'));

    $after = $page->script(headerWidth('created_at'));

    expect($after)->not->toBe($before)->toBeGreaterThanOrEqual(ResizableColumns::MIN_WIDTH)->toBeLessThanOrEqual(ResizableColumns::MAX_WIDTH);
    $page->assertNoJavaScriptErrors();
});

function narrowestHeaderState(string $column): string
{
    return sprintf(<<<'JS'
        (() => {
            const th = document.querySelector(`%s`);
            const cellRight = th.getBoundingClientRect().right;
            const text = th.querySelector('.fi-ta-header-cell-field > .truncate');
            const textBox = text.getBoundingClientRect();
            const barLeft = th.querySelector('.column-resize-handle-bar').getBoundingClientRect().left;
            return {
                width: Math.round(th.getBoundingClientRect().width),
                textVisible: textBox.width >= 16,
                textEllipsized: text.scrollWidth > text.clientWidth,
                textClearOfBar: textBox.right <= barLeft,
                textClearOfNext: textBox.right <= cellRight,
            };
        })()
    JS, headerSelector($column));
}

it('stops narrowing where a long name is ellipsized clear of the resize bar and the next header', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');
    $labels = [
        'name' => 'Annual recurring revenue per account',
        'created_at' => 'Date the first contract was signed',
        'custom_fields.domains' => 'Every domain the company owns',
    ];

    foreach ($labels as $column => $label) {
        $page->script('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.mountAction("editTableColumnLabel", { column: '.json_encode($column).' })');
        $page->assertVisible('.fi-modal-window input')
            ->type('.fi-modal-window input', $label)
            ->click('.fi-modal-window button[type="submit"]')
            ->assertMissing('.fi-modal-window')
            ->assertSeeIn(headerSelector($column), $label);
    }

    $states = [];

    foreach (array_keys($labels) as $column) {
        $page->script(dragColumnEdge($column, -$page->script(headerWidth($column))));
        $states[$column] = $page->script(narrowestHeaderState($column));

        expect($states[$column])->toMatchArray([
            'width' => ResizableColumns::MIN_WIDTH,
            'textVisible' => true,
            'textEllipsized' => true,
            'textClearOfBar' => true,
            'textClearOfNext' => true,
        ]);
    }

    $page->assertNoJavaScriptErrors();
});

it('fits a column so its header name and its widest cell stay in view', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Beekeepers International Holdings']);

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    foreach (['name', 'created_at'] as $column) {
        $page->script(doubleClickColumnEdge($column));
        $page->assertScript(fittedColumnShowsEverything($column), [true, true]);

        expect($page->script(narrowestHeaderState($column)))->toMatchArray(['textEllipsized' => false]);
    }

    $page->assertNoJavaScriptErrors();
});

it('keeps resizing after navigating to another table and after sorting', function (): void {
    $savedWidth = sprintf('(() => document.querySelector(`%s`).dataset.resizableWidth)()', headerSelector('name'));
    $oneHandlePerHeader = '(() => [...document.querySelectorAll(`th[x-data^="resizableColumn"]`)].every((th) => th.querySelectorAll(".column-resize-handle-bar").length === 1))()';

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->click("a.fi-sidebar-item-btn[href$='/app/{$this->workspace->slug}/people']")
        ->assertPathIs("/app/{$this->workspace->slug}/people")
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('name'));

    $page->script(dragColumnEdge('name', 120));
    $page->wait(1);

    $page->script('Livewire.all().find((component) => /List(Companies|People)/.test(component.name)).$wire.setFilterBarSorts([{ column: "name", direction: "asc" }])');

    $page->wait(1)
        ->assertScript($savedWidth, (string) ($before + 120))
        ->assertScript($oneHandlePerHeader, true)
        ->assertScript(dragFeedbackMidDrag('name'), true)
        ->assertNoJavaScriptErrors();
});

it('keeps the table head pinned while the rows scroll', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->count(30)->create();

    $headOffset = '(() => { const container = document.querySelector(".fi-ta-content-ctn"); return Math.abs(Math.round(document.querySelector("thead").getBoundingClientRect().top - container.getBoundingClientRect().top)); })()';

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/people", 1440, 700)
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $page->script('document.querySelector(".fi-ta-content-ctn").scrollTo(0, 600)');

    $page->wait(0.5)
        ->assertScript('document.querySelector(".fi-ta-content-ctn").scrollTop > 0', true)
        ->assertScript($headOffset, 0)
        ->assertNoJavaScriptErrors();
});

it('puts the horizontal scrollbar at the bottom of the screen with few records', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $page->assertScript(tableAreaBottomGap(), true)
        ->assertScript('(() => { const c = document.querySelector(".fi-ta-content-ctn"); return c.scrollWidth > c.clientWidth; })()', true)
        ->assertScript(pageScrollsVertically(), false)
        ->assertNoJavaScriptErrors();
});

it('puts the horizontal scrollbar at the bottom of the screen with many records and scrolls the rows inside the table', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->count(60)->create();

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/people", 1440, 900)
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $page->script('(() => { const select = document.querySelector(".fi-pagination-records-per-page-select:not(.fi-compact) select"); select.value = "50"; select.dispatchEvent(new Event("change", { bubbles: true })); })()');

    $page->wait(1.5)
        ->assertScript('document.querySelectorAll(".fi-ta-content-ctn tbody tr").length', 50)
        ->assertScript(tableAreaBottomGap(), true)
        ->assertScript(pageScrollsVertically(), false)
        ->assertScript('(() => { const c = document.querySelector(".fi-ta-content-ctn"); return c.scrollHeight > c.clientHeight + 1; })()', true)
        ->assertScript('document.querySelector(".fi-ta-ctn .fi-pagination") !== null', true)
        ->assertScript('(() => { const p = document.querySelector(".fi-ta-ctn .fi-pagination").getBoundingClientRect(); return p.top >= 0 && p.bottom <= window.innerHeight + 1; })()', true)
        ->assertNoJavaScriptErrors();
});

it('keeps the empty state inside the table area', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/tasks", 1440, 900);

    $page->assertScript('(() => { const empty = document.querySelector(".fi-ta-empty-state").getBoundingClientRect(); return empty.height > 200 && empty.bottom <= window.innerHeight + 1; })()', true)
        ->assertScript(pageScrollsVertically(), false)
        ->assertNoJavaScriptErrors();
});

it('resizes a column by the dragged amount when the table is narrower than the screen', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 2200, 900)
        ->assertVisible(headerSelector('accountOwner.name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('accountOwner.name'));

    $page->script(dragColumnEdge('accountOwner.name', 120));
    $page->assertScript(headerWidth('accountOwner.name'), $before + 120);

    $page->script(dragColumnEdge('accountOwner.name', -60));
    $page->assertScript(headerWidth('accountOwner.name'), $before + 60)
        ->assertNoJavaScriptErrors();
});

it('keeps a width when the table is sorted straight after the drag', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(dragColumnEdge('created_at', 120));
    $page->script('Livewire.all().find((component) => /List(Companies|People)/.test(component.name)).$wire.setFilterBarSorts([{ column: "name", direction: "asc" }])');

    $page->wait(1.5)
        ->assertScript(headerWidth('created_at'), $before + 120)
        ->assertNoJavaScriptErrors();
});

it('keeps a dragged width after signing in again', function (): void {
    $savedWidth = sprintf('(() => document.querySelector(`%s`).dataset.resizableWidth)()', headerSelector('created_at'));

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(dragColumnEdge('created_at', 120));
    $page->wait(1.5)
        ->assertScript($savedWidth, (string) ($before + 120));

    $page->script("document.querySelector('form[action$=\"/logout\"]').submit()");

    $page->assertPathIs('/app/login')
        ->navigate('/app/login')
        ->assertScript('!! (window.Livewire && window.Livewire.all().length > 0)', true)
        ->assertScript(asyncAlpineComponentsLoaded());

    loginViaBrowser($this->user, page: $page)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar')
        ->assertScript($savedWidth, (string) ($before + 120))
        ->assertNoJavaScriptErrors();
});

it('keeps a dragged width on a column that sets its own width, and the actions column gives up the space', function (): void {
    $actionsWidth = '(() => Math.round(document.querySelector("th.fi-ta-actions-header-cell").getBoundingClientRect().width))()';

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/people", 1920, 900)
        ->assertVisible(headerSelector('custom_fields.emails').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('custom_fields.emails'));
    $actionsBefore = $page->script($actionsWidth);

    $page->script(dragColumnEdge('custom_fields.emails', 100));
    $page->wait(1)
        ->assertScript(headerWidth('custom_fields.emails'), $before + 100)
        ->assertScript($actionsWidth, $actionsBefore - 100)
        ->refresh()
        ->assertVisible(headerSelector('custom_fields.emails').' .column-resize-handle-bar')
        ->assertScript(headerWidth('custom_fields.emails'), $before + 100)
        ->assertScript($actionsWidth, $actionsBefore - 100)
        ->assertNoJavaScriptErrors();
});

function refreshComponent(string $scope): string
{
    return sprintf(<<<'JS'
        (() => {
            const table = document.querySelector('table.fi-ta-resizable-table');
            const component = Livewire.all().find((candidate) => candidate.el.contains(table) === %s);
            component.$wire.$refresh();
            return true;
        })()
    JS, $scope);
}

it('rebuilds a table only when its own component morphs', function (): void {
    $isLocked = 'document.querySelector("table.fi-ta-resizable-table").dataset.resizableLayoutLocked === "1"';
    $unlock = 'delete document.querySelector("table.fi-ta-resizable-table").dataset.resizableLayoutLocked';

    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $page->script($unlock);
    $page->script(refreshComponent('false'));
    $page->wait(1)->assertScript($isLocked, false);

    $page->script(refreshComponent('true'));
    $page->wait(1)
        ->assertScript($isLocked, true)
        ->assertNoJavaScriptErrors();
});

it('releases a column at the last pointer position when several moves land in one frame', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900)
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const pointer = (type, clientX, target = document) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, button: 0, pointerId: 1, isPrimary: true, clientX, clientY: y }));
            pointer('pointerdown', x, handle);
            [20, 60, 140, 90].forEach((by) => pointer('pointermove', x + by));
            pointer('pointerup', x + 90);
            return true;
        })();
    JS, headerSelector('created_at')));

    $page->assertScript(headerWidth('created_at'), $before + 90)
        ->wait(1)
        ->assertScript(headerWidth('created_at'), $before + 90)
        ->assertNoJavaScriptErrors();
});

it('ends a text value cut by its column width with an ellipsis', function (): void {
    Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Send the updated proposal to the procurement team before Friday']);

    $title = 'document.querySelector("td.fi-ta-cell-title .fi-ta-text-item")';

    visitAs($this->user, "/app/{$this->workspace->slug}/tasks", 1440, 900)
        ->assertScript("getComputedStyle({$title}).textOverflow === 'ellipsis' && getComputedStyle({$title}).display === 'block'", true)
        ->assertScript("{$title}.scrollWidth > {$title}.clientWidth", true)
        ->assertNoJavaScriptErrors();
});

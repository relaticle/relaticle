<?php

declare(strict_types=1);

use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\ResizableColumns;
use App\Models\Company;
use App\Models\People;
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
            const badge = document.querySelector('.column-resize-badge');
            const feedback = {
                badge: badge !== null && badge.isConnected && getComputedStyle(badge).display !== 'none',
                handleActive: document.querySelector(`%s .column-resize-handle-bar`).classList.contains('active'),
            };
            document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1, isPrimary: true, clientX: x + 40, clientY: y }));
            return feedback.badge && feedback.handleActive;
        })();
    JS, headerSelector($column), headerSelector($column));
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
    People::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('resizes a column by dragging its edge and keeps the width after a reload', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
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

it('resizes a column with a finger and shows its edge on a touch screen', function (): void {
    $page = loginViaBrowser($this->user, page: $this->visit('/app/login')->on()->mobile()->assertPathIs('/app/login'))
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
            const handle = th.querySelector('.fi-ta-column-handle').getBoundingClientRect();
            const cells = [...document.querySelectorAll(`td.fi-ta-cell-${CSS.escape(th.dataset.resizableColumnId)}`)];
            return [
                rightEdge(th.querySelector('.fi-ta-header-cell-sort-btn')) <= handle.left,
                cells.every((td) => rightEdge(td) <= td.getBoundingClientRect().right),
            ];
        })()
    JS, headerSelector($column));
}

it('fits a column to its content on double-click', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
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
            const menuLeft = th.querySelector('.fi-ta-column-handle').getBoundingClientRect().left;
            const chevron = th.querySelector('.fi-ta-header-cell-sort-btn > svg')?.getBoundingClientRect();
            const text = th.querySelector('.fi-ta-header-cell-field > .truncate');
            const textBox = text.getBoundingClientRect();
            return {
                width: Math.round(th.getBoundingClientRect().width),
                textVisible: textBox.width >= 16,
                textEllipsized: text.scrollWidth > text.clientWidth,
                textClearOfMenu: textBox.right <= menuLeft,
                chevronAfterText: chevron ? chevron.width > 0 && chevron.left >= textBox.right : true,
                chevronClearOfMenu: chevron ? chevron.right <= menuLeft : true,
                chevronGap: chevron ? Math.round(menuLeft - chevron.right) : null,
            };
        })()
    JS, headerSelector($column));
}

it('stops narrowing where the name, the sort chevron and the options button still fit', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $states = [];

    foreach (['name', 'created_at', 'custom_fields.domains'] as $column) {
        $page->script(dragColumnEdge($column, -$page->script(headerWidth($column))));
        $states[$column] = $page->script(narrowestHeaderState($column));

        expect($states[$column])->toMatchArray([
            'width' => ResizableColumns::MIN_WIDTH,
            'textVisible' => true,
            'textEllipsized' => true,
            'textClearOfMenu' => true,
            'chevronAfterText' => true,
            'chevronClearOfMenu' => true,
        ]);
    }

    expect($states['name']['chevronGap'])->toBe($states['created_at']['chevronGap']);
    $page->assertNoJavaScriptErrors();
});

it('fits a column so its header controls and its widest cell stay in view', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Beekeepers International Holdings']);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    foreach (['name', 'created_at'] as $column) {
        $page->script(doubleClickColumnEdge($column));
        $page->assertScript(fittedColumnShowsEverything($column), [true, true]);
    }

    $page->assertNoJavaScriptErrors();
});

it('keeps resizing after navigating to another table and after sorting', function (): void {
    $savedWidth = sprintf('(() => document.querySelector(`%s`).dataset.resizableWidth)()', headerSelector('name'));
    $oneHandlePerHeader = '(() => [...document.querySelectorAll(`th[x-data^="resizableColumn"]`)].every((th) => th.querySelectorAll(".column-resize-handle-bar").length === 1))()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click("a.fi-sidebar-item-btn[href$='/app/{$this->workspace->slug}/people']")
        ->assertPathIs("/app/{$this->workspace->slug}/people")
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('name'));

    $page->script(dragColumnEdge('name', 120));
    $page->wait(1);

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', headerSelector('name')));

    $page->wait(1)
        ->assertScript($savedWidth, (string) ($before + 120))
        ->assertScript($oneHandlePerHeader, true)
        ->assertScript(dragFeedbackMidDrag('name'), true)
        ->assertNoJavaScriptErrors();
});

it('keeps the table head pinned while the page scrolls', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->count(30)->create();

    $headOffset = '(() => { const layout = document.querySelector(".fi-layout"); return Math.abs(Math.round(document.querySelector("thead").getBoundingClientRect().top - layout.getBoundingClientRect().top)); })()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 700)
        ->navigate("/app/{$this->workspace->slug}/people")
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $page->script('document.querySelector(".fi-layout").scrollTo(0, 600)');

    $page->wait(0.5)
        ->assertScript($headOffset, 0)
        ->assertNoJavaScriptErrors();
});

it('resizes a column by the dragged amount when the table is narrower than the screen', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(2200, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('accountOwner.name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('accountOwner.name'));

    $page->script(dragColumnEdge('accountOwner.name', 120));
    $page->assertScript(headerWidth('accountOwner.name'), $before + 120);

    $page->script(dragColumnEdge('accountOwner.name', -60));
    $page->assertScript(headerWidth('accountOwner.name'), $before + 60)
        ->assertNoJavaScriptErrors();
});

it('keeps a width when the table is sorted straight after the drag', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(dragColumnEdge('created_at', 120));
    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', headerSelector('name')));

    $page->wait(1.5)
        ->assertScript(headerWidth('created_at'), $before + 120)
        ->assertNoJavaScriptErrors();
});

it('keeps a dragged width after signing in again', function (): void {
    $savedWidth = sprintf('(() => document.querySelector(`%s`).dataset.resizableWidth)()', headerSelector('created_at'));

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
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

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1920, 900)
        ->navigate("/app/{$this->workspace->slug}/people")
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

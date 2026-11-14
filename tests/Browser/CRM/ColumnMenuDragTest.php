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

function countLivewireRequests(): string
{
    return 'window.__requests = 0; Livewire.hook("request", () => { window.__requests++ }); true';
}

function dragLeftovers(): string
{
    return '!!document.querySelector(".fi-ta-column-drag-ghost") || !!document.querySelector(".fi-ta-column-dragging") || [...document.querySelectorAll("th, td")].some((cell) => cell.style.transform !== "")';
}

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

<?php

declare(strict_types=1);

use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

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

function removeFromSettings(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    return $page->click(settingsRow($column, '[data-view-settings-menu]'))
        ->click(settingsMenuItem('remove'));
}

function settingsOrder(): string
{
    return '[...document.querySelectorAll(".fi-view-settings-row")].map((row) => row.dataset.column)';
}

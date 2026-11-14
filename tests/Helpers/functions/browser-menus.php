<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;

function menuMetrics(string $panel, string $rows, string $icons): string
{
    return <<<JS
        (async () => {
            const panel = document.querySelector('{$panel}');
            const visible = (element) => element.getClientRects().length > 0;
            const visibleRows = () => [...panel.querySelectorAll('{$rows}')].filter(visible);
            const unique = (values) => [...new Set(values)];
            const settle = () => new Promise((resolve) => setTimeout(resolve, 100));

            for (let attempt = 0, count = -1; attempt < 20 && count !== visibleRows().length; attempt++) {
                count = visibleRows().length;
                await settle();
            }

            const rows = visibleRows();

            return {
                rows: unique(rows.map((row) => Math.round(row.getBoundingClientRect().height))),
                pitch: unique(rows.slice(1).map((row, index) => [row, rows[index]]).filter(([row, previous]) => row.parentElement === previous.parentElement).map(([row, previous]) => Math.round(row.getBoundingClientRect().top - previous.getBoundingClientRect().top))),
                icons: unique([...panel.querySelectorAll('{$icons}')].filter(visible).map((icon) => Math.round(icon.getBoundingClientRect().width))),
                panelRadius: getComputedStyle(panel.closest('.fi-dropdown-panel, .fi-filter-pop, .fi-merge-pop')).borderRadius,
                rowRadius: unique(rows.map((row) => getComputedStyle(row).borderRadius)),
            };
        })()
        JS;
}

function menuRoom(string $panel, string $list): string
{
    return <<<JS
        (() => {
            const panel = document.querySelector('{$panel}').closest('.fi-dropdown-panel, .fi-filter-pop, .fi-merge-pop');
            const box = panel.getBoundingClientRect();
            const list = panel.querySelector('{$list}');

            return {
                height: box.height <= 448,
                bottom: box.bottom <= window.innerHeight - 16,
                listScrolls: list.scrollHeight > list.clientHeight,
                panelScrolls: panel.scrollHeight > panel.clientHeight + 1,
            };
        })()
        JS;
}

function holdLook(array $targets, string $trigger): string
{
    $selectors = json_encode($targets);
    $triggerJson = json_encode($trigger);

    return <<<JS
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 400));

            return Object.fromEntries({$selectors}.map((selector) => {
                const [base, pseudo] = selector.split('::');
                const element = [...document.querySelectorAll(base.replace(':visible', ''))].find((candidate) => candidate.getClientRects().length > 0);
                const style = getComputedStyle(element, pseudo ? `::\${pseudo}` : null);
                const opacity = selector === {$triggerJson} ? '' : style.opacity;

                return [selector, [style.backgroundColor, style.color, style.boxShadow, style.borderRightColor, opacity, pseudo ? style.width : ''].join(' | ')];
            }));
        })()
        JS;
}

function expectHoverHeldWhileOpen(AwaitableWebpage $page, string $item, string $trigger, array $extras = [], bool $dark = false, string $away = '.fi-sidebar-nav .fi-sidebar-item-btn >> nth=0'): void
{
    if ($dark) {
        $page->script('document.documentElement.classList.add("dark")');
    }

    $targets = array_values(array_unique([$item, $trigger, ...$extras]));

    $page->hover($item)->hover($trigger);
    $hoveredTrigger = $page->script(holdLook([$trigger], $trigger))[$trigger];
    $page->hover($item);
    $hovered = $page->script(holdLook($targets, $trigger));
    $hovered[$trigger] = $hoveredTrigger;

    $page->hover($away);
    $resting = $page->script(holdLook($targets, $trigger));
    expect($resting)->not->toBe($hovered);

    $page->click($trigger)->assertAttribute($trigger, 'aria-expanded', 'true');
    $page->script('document.activeElement.blur()');
    $page->hover($away);

    expect($page->script(holdLook($targets, $trigger)))->toBe($hovered);
}

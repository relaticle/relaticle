<?php

declare(strict_types=1);

pest()->group('webkit');

it('opens the visible selected result from the documentation search box', function (): void {
    $this->withVite();
    config()->set('documentation.public_url', '');
    $page = $this->visit('/help')->resize(393, 851);
    $trigger = 'button[aria-label="Search the docs"]';
    $input = '[role="combobox"]';
    $selected = '[role="option"][aria-selected="true"]';
    $settled = '(() => { const nav = document.querySelector(\'nav[aria-label="Search the docs"]\'); return getComputedStyle(nav).opacity === "1" && nav.getAnimations({ subtree: true }).length === 0; })()';

    $page->click($trigger)
        ->fill($input, 'workspace')
        ->assertPresent($selected)
        ->assertScript($settled, true)
        ->assertNoAccessibilityIssues()
        ->keys($input, 'ArrowUp');

    expect($page->script(<<<'JS'
        (() => {
            const input = document.activeElement;
            const result = document.getElementById(input.getAttribute('aria-activedescendant'));
            const list = document.getElementById(input.getAttribute('aria-controls'));
            const last = [...list.querySelectorAll('[role="option"]')].at(-1);
            return result === last && result.getBoundingClientRect().bottom <= list.getBoundingClientRect().bottom + 1;
        })()
    JS))->toBeTrue();

    $destination = $page->attribute($selected, 'href');
    $page->keys($input, 'Enter')
        ->assertPathIs(parse_url($destination, PHP_URL_PATH))
        ->assertFragmentIs((string) parse_url($destination, PHP_URL_FRAGMENT));

    $page->click($trigger)
        ->fill($input, 'workspace')
        ->click("[role=\"option\"][href=\"{$destination}\"]")
        ->assertMissing('dialog:modal');

    $page->click($trigger)
        ->fill($input, 'workspace')
        ->assertPresent($selected)
        ->keys($input, 'ArrowUp')
        ->keys($input, 'Enter')
        ->assertMissing('dialog:modal')
        ->assertNoJavaScriptErrors();
});

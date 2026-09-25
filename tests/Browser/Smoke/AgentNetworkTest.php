<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

it('dismisses a connection preview with Escape while retaining keyboard focus', function (): void {
    $this->visit('/')
        ->keys('[data-network-node="claude"]', 'Enter')
        ->assertPresent('[data-network-node="claude"][data-active]')
        ->keys('[data-network-node="claude"]', 'Escape')
        ->assertMissing('[data-network-node][data-active]')
        ->assertScript('document.activeElement.dataset.networkNode', 'claude')
        ->assertNoJavaScriptErrors();
});

it('clears a clicked mouse preview when the pointer leaves the node', function (): void {
    $this->visit('/')
        ->click('[data-network-node="claude"]')
        ->assertPresent('[data-network-node="claude"][data-active]')
        ->hover('[data-network-hub]')
        ->assertMissing('[data-network-node][data-active]')
        ->assertNoJavaScriptErrors();
});

it('exposes the current connection and toggles repeated keyboard activation', function (): void {
    $this->visit('/')
        ->keys('[data-network-node="claude"]', 'Enter')
        ->assertAttribute('[data-network-node="claude"]', 'aria-pressed', 'true')
        ->keys('[data-network-node="chatgpt"]', 'Enter')
        ->assertAttribute('[data-network-node="claude"]', 'aria-pressed', 'false')
        ->assertAttribute('[data-network-node="chatgpt"]', 'aria-pressed', 'true')
        ->keys('[data-network-node="chatgpt"]', 'Enter')
        ->assertMissing('[data-network-node][data-active]')
        ->assertAttribute('[data-network-node="chatgpt"]', 'aria-pressed', 'false')
        ->assertNoJavaScriptErrors();
});

it('replaces a touch preview and clears it on a repeated tap', function (): void {
    $page = $this->visit('/')->on()->mobile()
        ->assertVisible('[data-network-node="claude"]');

    $page->page()->locator('[data-network-node="claude"]')->tap();
    $page->assertAttribute('[data-network-node="claude"]', 'aria-pressed', 'true');

    $page->page()->locator('[data-network-node="chatgpt"]')->tap();
    $page->assertAttribute('[data-network-node="claude"]', 'aria-pressed', 'false')
        ->assertAttribute('[data-network-node="chatgpt"]', 'aria-pressed', 'true');

    $page->page()->locator('[data-network-node="chatgpt"]')->tap();
    $page->assertMissing('[data-network-node][data-active]')
        ->assertAttribute('[data-network-node="chatgpt"]', 'aria-pressed', 'false')
        ->assertNoJavaScriptErrors();
});

it('runs the connection loop only while the pointer is inside the diagram', function (): void {
    $page = $this->visit('/');
    $page->script('document.querySelector("[data-agent-network]").scrollIntoView({ block: "center" })');
    $page->page()->waitForFunction('[...document.querySelectorAll("[data-network-lines] path")].every(path => path.getAnimations().length === 0)');

    $page->assertScript('[...document.querySelectorAll("[data-network-pulse]")].every(comet => comet.getAnimations().length === 0)', true);

    $page->page()->locator('[data-network-status]')->hover(['force' => true]);
    $page->page()->waitForFunction('[...document.querySelectorAll("[data-network-pulse]")].some(comet => comet.getAnimations().length > 0)');

    $page->page()->locator('#features h2')->hover(['force' => true]);
    $page->assertScript('[...document.querySelectorAll("[data-network-pulse]")].every(comet => comet.getAnimations().length === 0)', true)
        ->assertNoJavaScriptErrors();
});

it('pauses the connection loop while a node is previewed and sweeps only its routes', function (): void {
    $page = $this->visit('/');
    $page->script('document.querySelector("[data-agent-network]").scrollIntoView({ block: "center" })');
    $page->page()->locator('[data-network-status]')->hover(['force' => true]);
    $page->page()->waitForFunction('[...document.querySelectorAll("[data-network-pulse]")].some(comet => comet.getAnimations().length > 0)');

    $page->page()->locator('[data-network-node="people"]')->hover(['force' => true]);

    $page->assertScript('document.querySelector(\'[data-network-pulse="people"]\').getAnimations().length', 1)
        ->assertScript('document.querySelector(\'[data-network-pulse="deals"]\').getAnimations().length', 0)
        ->assertScript('[...document.querySelectorAll("[data-network-lines] path[stroke-width=\"1.5\"]")].filter(route => route.style.opacity === "1").length', 5)
        ->assertNoJavaScriptErrors();
});

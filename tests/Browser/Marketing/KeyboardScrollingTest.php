<?php

declare(strict_types=1);

pest()->group('webkit');

it('lets a keyboard reader scroll the conversation and its table during the homepage demo', function (): void {
    $this->withVite();
    $page = $this->visit('/')->resize(393, 851);
    $conversation = '[x-ref="messagesScroll"]';
    $table = '.mcp-tasks-table .overflow-x-auto';

    $page->keys('#tab-ai-agent', 'Tab');

    expect($page->script("document.activeElement.matches('{$conversation}')"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-hero-demo]').dataset.demoState"))->toBe('done');

    $page->page()->locator($conversation)->press('ArrowDown', ['delay' => 120]);
    $page->assertScript("document.querySelector('{$conversation}').scrollTop > 0", true);

    $page->keys($conversation, 'Tab');

    expect($page->script("document.activeElement.matches('{$table}')"))->toBeTrue();

    $page->page()->locator($table)->press('ArrowRight', ['delay' => 120]);
    $page->assertScript("document.querySelector('{$table}').scrollLeft > 0", true);

    $page->keys($table, 'Tab');

    expect($page->script("document.querySelector('{$conversation}').contains(document.activeElement)"))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

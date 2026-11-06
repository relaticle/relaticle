<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function assistantPlayground(mixed $page, string $expression): mixed
{
    return $page->script("(() => { const root = document.querySelector('[data-assistant-playground]'); return {$expression}; })()");
}

it('switches between asks and shows the record each write produced', function (): void {
    $page = $this->visit('/crm-for-claude');
    $page->wait(1);

    expect(assistantPlayground($page, "getComputedStyle(root.querySelector('[data-assistant-ask=\"pipeline\"]')).visibility"))->toBe('visible')
        ->and(assistantPlayground($page, "getComputedStyle(root.querySelector('[data-assistant-ask=\"task\"]')).visibility"))->toBe('hidden')
        ->and(assistantPlayground($page, "root.querySelector('[data-assistant-ask=\"pipeline\"] table') !== null"))->toBeTrue();

    $page->click('#assistant-ask-task');
    $page->wait(0.3);

    expect(assistantPlayground($page, "getComputedStyle(root.querySelector('[data-assistant-ask=\"task\"]')).visibility"))->toBe('visible')
        ->and(assistantPlayground($page, "getComputedStyle(root.querySelector('[data-assistant-ask=\"pipeline\"]')).visibility"))->toBe('hidden')
        ->and(assistantPlayground($page, "root.querySelector('[data-assistant-ask=\"task\"] [data-assistant-record]').innerText.replace(/\\s+/g, ' ')"))->toContain('Send the proposal')
        ->and(assistantPlayground($page, "root.querySelector('[data-assistant-ask=\"task\"] [data-assistant-record]').innerText"))->toContain('Claude');

    $page->keys('#assistant-ask-task', ['ArrowRight']);
    $page->wait(0.3);

    expect(assistantPlayground($page, 'document.activeElement.id'))->toBe('assistant-ask-notes')
        ->and(assistantPlayground($page, "getComputedStyle(root.querySelector('[data-assistant-ask=\"notes\"]')).visibility"))->toBe('visible');

    $page->assertNoJavaScriptErrors();
});

it('prefixes every ChatGPT ask with the plugin mention', function (): void {
    $page = $this->visit('/crm-for-chatgpt');
    $page->wait(1);

    expect(assistantPlayground($page, "[...root.querySelectorAll('[data-assistant-ask] p.rounded-br-md')].map((bubble) => bubble.textContent.trim().startsWith('@Relaticle'))"))->toBe([true, true, true, true])
        ->and(assistantPlayground($page, "root.querySelector('[data-assistant-ask=\"person\"] [data-assistant-record]').textContent"))->toContain('ChatGPT');

    $page->assertNoJavaScriptErrors();
});

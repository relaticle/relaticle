<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function aiNativeChecklist(mixed $page, string $expression): mixed
{
    return $page->script("(() => { const root = document.querySelector('[data-ai-native-checklist]'); return {$expression}; })()");
}

it('scores the visitor\'s CRM as checks are ticked and unticked', function (): void {
    $page = $this->visit('/ai-native-crm');
    $page->wait(1);

    expect(aiNativeChecklist($page, "root.querySelector('[data-ai-native-score]').innerText.replace(/\\s+/g, ' ')"))->toBe('Your CRM 0 of 5 · Relaticle 5 of 5');

    $page->click('[data-ai-native-check]:nth-child(2) [data-ai-native-toggle]');
    $page->click('[data-ai-native-check]:nth-child(4) [data-ai-native-toggle]');
    $page->wait(0.3);

    expect(aiNativeChecklist($page, "root.querySelector('[data-ai-native-yours]').innerText"))->toBe('2')
        ->and(aiNativeChecklist($page, "[...root.querySelectorAll('[data-ai-native-toggle]')].map((toggle) => toggle.getAttribute('aria-pressed'))"))->toBe(['true', 'false', 'true', 'false', 'false'])
        ->and(aiNativeChecklist($page, "root.querySelector('[data-ai-native-check]:nth-child(2) [data-ai-native-toggle]').innerText.trim()"))->toContain('Yes');

    $page->click('[data-ai-native-check]:nth-child(2) [data-ai-native-toggle]');
    $page->wait(0.3);

    expect(aiNativeChecklist($page, "root.querySelector('[data-ai-native-yours]').innerText"))->toBe('1');

    $page->assertNoJavaScriptErrors();
});

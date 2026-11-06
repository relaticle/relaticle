<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function aiPlayground(mixed $page, string $expression): mixed
{
    return $page->script("(() => { const root = document.querySelector('[data-ai-playground]'); return {$expression}; })()");
}

function aiFocusState(mixed $page): mixed
{
    return aiPlayground($page, <<<'JS'
        (() => {
            const focused = document.activeElement;
            const hidden = [focused, ...root.querySelectorAll('*')]
                .filter((element) => element.contains(focused))
                .some((element) => getComputedStyle(element).visibility === 'hidden' || getComputedStyle(element).opacity === '0');

            return {
                inPlayground: root.contains(focused),
                hidden,
                result: focused.hasAttribute('data-ai-batch-decided') || focused.hasAttribute('data-ai-create-done') || focused.hasAttribute('data-ai-create-discarded'),
            };
        })()
        JS);
}

it('decides a batch one record at a time and reports what it saved', function (): void {
    $page = $this->visit('/ai');
    $page->wait(1);

    expect(aiPlayground($page, "root.querySelector('[data-ai-batch-record]').innerText"))->toBe('Kovra renewal');

    $page->click('[data-ai-batch-save]');

    expect(aiPlayground($page, "root.querySelector('[data-ai-batch-record]').innerText"))->toBe('Kovra add-on seats');

    $page->click('[data-ai-scenario="batch"] button:text-is("Discard")');
    $page->click('[data-ai-batch-save]');
    $page->wait(0.5);

    expect(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-batch-decided]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-batch-card]')).visibility"))->toBe('hidden')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-scenario=\"batch\"] p[x-text]').innerText"))
        ->toBe('Done. Moved 2 of 3 opportunities to Negotiation/Review.')
        ->and(aiPlayground($page, "[...root.querySelectorAll('[data-ai-batch-decided] li')].map((item) => item.innerText.replace(/\\s+/g, ' ').trim())"))
        ->toBe(['Kovra renewal Updated', 'Kovra add-on seats Skipped', 'Kovra platform expansion Updated'])
        ->and(aiPlayground($page, "[...root.querySelectorAll('[data-ai-deal-stage]')].map((stage) => stage.innerText)"))
        ->toBe(['Negotiation/Review', 'Proposal/Price Quote', 'Negotiation/Review']);

    $page->assertNoJavaScriptErrors();
});

it('creates a person with only the fields the visitor keeps', function (): void {
    $page = $this->visit('/ai');

    expect(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-person-row]')).visibility"))->toBe('hidden');

    $page->click('#ai-ask-create');
    $page->wait(1);
    $page->click('[aria-label="Include Company"]');
    $page->click('[data-ai-create-confirm]');

    expect(aiPlayground($page, "root.querySelector('[data-ai-scenario=\"create\"] p[x-text]').innerText"))->toBe('Sarah Chen has been created.')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-create-done]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-person-row]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-person-row]').innerText.replace(/\\s+/g, ' ').trim()"))->toBe('Sarah Chen VP of Engineering');

    $page->assertNoJavaScriptErrors();
});

it('moves between asks with the arrow keys', function (): void {
    $page = $this->visit('/ai');

    $page->keys('#ai-ask-batch', ['ArrowRight']);
    $page->wait(0.3);

    expect(aiPlayground($page, 'document.activeElement.id'))->toBe('ai-ask-create')
        ->and(aiPlayground($page, "root.querySelector('#ai-ask-create').getAttribute('aria-selected')"))->toBe('true')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-scenario=\"create\"]')).visibility"))->toBe('visible');

    $page->assertNoJavaScriptErrors();
});

it('keeps focus on a visible result once the visitor has decided', function (): void {
    $page = $this->visit('/ai');
    $page->wait(1);

    $page->click('[data-ai-batch-save]');
    $page->click('[data-ai-batch-save]');
    $page->click('[data-ai-batch-save]');
    $page->wait(0.6);

    expect(aiFocusState($page))->toBe(['inPlayground' => true, 'hidden' => false, 'result' => true]);

    $page->click('#ai-ask-create');
    $page->wait(1);
    $page->click('[data-ai-create-confirm]');
    $page->wait(0.6);

    expect(aiFocusState($page))->toBe(['inPlayground' => true, 'hidden' => false, 'result' => true]);

    $page->assertNoJavaScriptErrors();
});

it('jumps to the first and last ask with Home and End', function (): void {
    $page = $this->visit('/ai');

    $page->keys('#ai-ask-batch', ['End']);
    $page->wait(0.3);

    expect(aiPlayground($page, 'document.activeElement.id'))->toBe('ai-ask-read');

    $page->keys('#ai-ask-read', ['Home']);
    $page->wait(0.3);

    expect(aiPlayground($page, 'document.activeElement.id'))->toBe('ai-ask-batch');

    $page->assertNoJavaScriptErrors();
});

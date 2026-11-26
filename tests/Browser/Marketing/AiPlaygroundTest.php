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

it('approves a batch with one record left out and reports what it moved', function (): void {
    $page = $this->visit('/ai');
    $page->wait(1);

    expect(aiPlayground($page, "[...root.querySelectorAll('[data-ai-batch-record]')].map((row) => row.innerText.replace(/\\s+/g, ' ').trim())"))
        ->toBe([
            'Kovra renewal Stage Qualification Negotiation/Review',
            'Kovra add-on seats Stage Proposal/Price Quote Negotiation/Review',
            'Kovra platform expansion Stage Qualification Negotiation/Review',
        ])
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-composer]')).display"))->toBe('none');

    $page->click('[aria-label="Leave out Kovra add-on seats"]');

    expect(aiPlayground($page, "root.querySelector('[data-ai-batch-save]').innerText.trim()"))->toStartWith('Approve 2 changes');

    $page->click('[data-ai-batch-save]');
    $page->wait(0.5);

    expect(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-batch-decided]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-batch-card]')).display"))->toBe('none')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-composer]')).display"))->not->toBe('none')
        ->and(aiPlayground($page, "[...root.querySelectorAll('[data-ai-batch-decided] [data-mock-receipt-line]')].filter((line) => line.offsetParent !== null).map((line) => line.innerText.replace(/\\s+/g, ' ').trim())"))
        ->toBe(['Moved 2 opportunities to Negotiation/Review · $86,000.00', 'Kovra add-on seats · left out'])
        ->and(aiPlayground($page, "root.querySelector('[data-ai-batch-followup]').innerText"))->toBe('Done. Moved 2 of 3 opportunities to Negotiation/Review.')
        ->and(aiPlayground($page, "[...root.querySelectorAll('[data-ai-deal-row]')].map((row) => row.querySelector('td:last-child').innerText.trim())"))
        ->toBe(['Negotiation/Review', 'Proposal/Price Quote', 'Negotiation/Review']);

    $page->assertNoJavaScriptErrors();
});

it('counts a single kept opportunity in the singular', function (): void {
    $page = $this->visit('/ai');
    $page->wait(1);

    $page->click('[aria-label="Leave out Kovra add-on seats"]');
    $page->click('[aria-label="Leave out Kovra platform expansion"]');

    expect(aiPlayground($page, "root.querySelector('[data-ai-batch-save]').innerText.trim()"))->toStartWith('Approve 1 change')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-batch-save]').innerText.trim()"))->not->toContain('changes');

    $page->click('[data-ai-batch-save]');
    $page->wait(0.5);

    expect(aiPlayground($page, "root.querySelector('[data-ai-batch-decided] p').innerText.trim()"))->toBe('Applied 1 change · just now')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-batch-decided] [data-mock-receipt-line]').innerText.replace(/\\s+/g, ' ').trim()"))->toBe('Moved 1 opportunity to Negotiation/Review · $24,000.00');

    $page->assertNoJavaScriptErrors();
});

it('creates a person with only the fields the visitor keeps', function (): void {
    $page = $this->visit('/ai');

    expect(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-person-row]')).visibility"))->toBe('hidden');

    $page->click('#ai-ask-create');
    $page->wait(1);
    $page->click('[aria-label="Leave out Job Title"]');
    $page->click('[data-ai-create-confirm]');

    expect(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-create-done]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-create-done] [data-mock-receipt-line]').innerText.replace(/\\s+/g, ' ').trim()"))->toBe('Created Sarah Chen · (Job Title left out)')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-create-followup]').innerText"))->toBe('Sarah Chen has been created at Kovra Systems.')
        ->and(aiPlayground($page, "getComputedStyle(root.querySelector('[data-ai-person-row]')).visibility"))->toBe('visible')
        ->and(aiPlayground($page, "root.querySelector('[data-ai-person-row]').innerText.replace(/\\s+/g, ' ').trim()"))->toBe('Sarah Chen Kovra Systems');

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

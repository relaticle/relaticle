<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    $this->withVite();
});

function scrollShowcaseIntoView(mixed $page, string $root): void
{
    $page->script("document.querySelector('{$root}').closest('.feat-card').scrollIntoView({ block: 'center' })");
}

function showcaseDemoState(mixed $page, string $root): mixed
{
    return $page->script(<<<JS
        (() => {
            const root = document.querySelector('{$root}');

            return { state: root.dataset.demoState, animations: root.getAnimations({ subtree: true }).length };
        })()
    JS);
}

function waitForShowcase(mixed $page, string $condition): mixed
{
    return $page->script(<<<JS
        (async () => {
            const until = Date.now() + 8000;

            while (Date.now() < until) {
                if ({$condition}) {
                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 100));
            }

            return false;
        })()
    JS);
}

function approvalOutcome(mixed $page): mixed
{
    return $page->script(<<<'JS'
        (() => {
            const demo = document.querySelector('[data-approval]');
            const visibility = (selector) => getComputedStyle(demo.querySelector(selector)).visibility;

            return {
                decision: demo.dataset.decision,
                pending: visibility('[data-approval-pending]'),
                done: visibility('[data-approval-done]'),
                discarded: visibility('[data-approval-discarded]'),
                followup: visibility('[data-approval-followup]'),
            };
        })()
    JS);
}

it('plays a showcase demo when it scrolls into view and resets it off screen', function (): void {
    $page = $this->visit('/');

    expect(showcaseDemoState($page, '[data-approval]')['state'])->toBe('idle');

    scrollShowcaseIntoView($page, '[data-approval]');
    $page->wait(1);

    expect(showcaseDemoState($page, '[data-approval]')['state'])->toBe('playing');

    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.5);

    $state = showcaseDemoState($page, '[data-approval]');

    expect($state['state'])->toBe('idle')
        ->and($state['animations'])->toBe(0);

    $page->assertNoJavaScriptErrors();
});

it('docks the proposal and waits for the visitor to decide', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-approval]');

    $docked = waitForShowcase($page, <<<'JS'
        (() => {
            const demo = document.querySelector('[data-approval]');
            const card = demo.querySelector('[data-approval-card]');

            return demo.dataset.demoState === 'done' && getComputedStyle(card).opacity === '1';
        })()
    JS);

    expect($docked)->toBeTrue()
        ->and(approvalOutcome($page))->toBe(['decision' => 'pending', 'pending' => 'visible', 'done' => 'hidden', 'discarded' => 'hidden', 'followup' => 'hidden']);

    $page->assertNoJavaScriptErrors();
});

it('saves the change when the visitor approves it and Rela confirms', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-approval]');
    $page->click('[data-approval-button]');

    expect(approvalOutcome($page))->toBe(['decision' => 'approved', 'pending' => 'hidden', 'done' => 'visible', 'discarded' => 'hidden', 'followup' => 'visible'])
        ->and($page->script("document.querySelector('[data-approval-followup]').textContent"))->toStartWith('Done. Northwind renewal is Closed Won, closing');

    $page->assertNoJavaScriptErrors();
});

it('writes nothing when the visitor discards the proposal', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-approval]');
    $page->click('[data-approval-pending] button:first-child');

    expect(approvalOutcome($page))->toBe(['decision' => 'discarded', 'pending' => 'hidden', 'done' => 'hidden', 'discarded' => 'visible', 'followup' => 'visible'])
        ->and($page->script("document.querySelector('[data-approval-followup]').textContent"))->toBe('Okay, I left Northwind renewal as it was.');

    $page->assertNoJavaScriptErrors();
});

it('saves an edited close date and leaves an unticked field out', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-approval]');
    $page->click('[aria-label="Edit Close date"]');
    $page->fill('[data-approval] input[type="date"]', '2026-11-20');
    $page->click('[data-approval-field="closeIncluded"] button:text-is("Save")');
    $page->click('[aria-label="Include Stage"]');
    $page->click('[data-approval-button]');

    expect($page->script("document.querySelector('[data-approval-close-date]').textContent"))->toBe('Nov 20, 2026')
        ->and($page->script("document.querySelector('[data-approval-followup]').textContent"))->toBe('Done. Northwind renewal now closes Nov 20, 2026. The stage stays Negotiation/Review.');

    $page->assertNoJavaScriptErrors();
});

it('switches the agent conversation between Claude and ChatGPT', function (): void {
    $page = $this->visit('/');

    $actors = <<<'JS'
        [...document.querySelectorAll('[data-agent-actor] > span')].map((chip) => `${chip.textContent.trim()}:${getComputedStyle(chip).visibility}`)
    JS;

    expect($page->script($actors))->toBe(['Claude:visible', 'ChatGPT:hidden']);

    $page->click('#agent-tab-chatgpt');

    expect($page->script($actors))->toBe(['Claude:hidden', 'ChatGPT:visible'])
        ->and($page->script("document.querySelector('#agent-tab-chatgpt').getAttribute('aria-selected')"))->toBe('true');

    $page->assertNoJavaScriptErrors();
});

it('moves the board card into the next stage and updates both counts', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-board]');

    $moved = waitForShowcase($page, <<<'JS'
        (() => {
            const board = document.querySelector('[data-board]');
            const counts = [...board.querySelectorAll('[data-board-count]')]
                .map((count) => [...count.children].find((span) => ! span.classList.contains('invisible')).textContent.trim());

            return board.querySelector('[data-board-card]').style.transform.startsWith('translate(') && counts.join(',') === '1,2';
        })()
    JS);

    expect($moved)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('fills the record from the mailbox and lands the sharing locks last', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);

    $page = $this->visit('/');

    expect(showcaseDemoState($page, '#email [data-mail-demo]')['state'])->toBe('idle');

    scrollShowcaseIntoView($page, '#email [data-mail-demo]');

    $filled = waitForShowcase($page, <<<'JS'
        (() => {
            const demo = document.querySelector('#email [data-mail-demo]');
            const parts = [...demo.querySelectorAll('[data-mail-caption], [data-mail-row], [data-mail-lock]')];

            return demo.dataset.demoState === 'done' && parts.length === 7 && parts.every((part) => getComputedStyle(part).opacity === '1');
        })()
    JS);

    expect($filled)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('switches the record between its emails and its meetings', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);

    $page = $this->visit('/');

    $panels = <<<'JS'
        [...document.querySelectorAll('#email [role="tabpanel"]')].map((panel) => getComputedStyle(panel).visibility)
    JS;

    expect($page->script($panels))->toBe(['visible', 'hidden']);

    $page->click('#email [role="tab"][aria-controls$="-meetings-panel"]');

    expect($page->script($panels))->toBe(['hidden', 'visible'])
        ->and($page->script('document.querySelector(\'#email [role="tab"][aria-selected="true"]\').textContent.trim()'))->toStartWith('Meetings');

    $page->assertNoJavaScriptErrors();
});

it('plays the four-hands story on a landscape phone shorter than the stacked story', function (): void {
    $page = $this->visit('/')->resize(844, 390);

    $page->script(<<<'JS'
        (async () => {
            const story = document.querySelector('[data-story]');
            const top = story.getBoundingClientRect().top + window.scrollY;

            for (let y = top - innerHeight; y < top + story.offsetHeight; y += 120) {
                window.scrollTo(0, y);
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })()
    JS);

    $revealed = waitForShowcase($page, "[...document.querySelectorAll('[data-story-step]')].every((step) => getComputedStyle(step).opacity === '1')");

    expect($revealed)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

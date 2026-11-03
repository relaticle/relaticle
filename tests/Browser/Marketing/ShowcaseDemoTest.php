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

it('plays the approval through to the saved state', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page, '[data-approval]');

    $saved = waitForShowcase($page, <<<'JS'
        (() => {
            const done = document.querySelector('[data-approval-done]');
            const pending = document.querySelector('[data-approval-pending]');

            return ! done.classList.contains('invisible') && getComputedStyle(done).opacity === '1' && pending.classList.contains('invisible');
        })()
    JS);

    expect($saved)->toBeTrue()
        ->and(showcaseDemoState($page, '[data-approval]')['state'])->toBe('done');

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

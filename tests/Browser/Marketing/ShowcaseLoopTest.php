<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function scrollShowcaseIntoView(mixed $page): void
{
    $page->script("document.querySelector('[data-approval]').scrollIntoView({ block: 'center' })");
}

function showcaseLoopState(mixed $page, string $root): mixed
{
    return $page->script(<<<JS
        (() => {
            const root = document.querySelector('{$root}');
            const done = root.querySelector('[data-approval-done]');

            return {
                state: root.dataset.loopState,
                doneShown: done ? ! done.classList.contains('invisible') && getComputedStyle(done).opacity === '1' : null,
                toggleLabel: root.closest('.feat-card').querySelector('[data-loop-toggle]').getAttribute('aria-label'),
                animations: root.getAnimations({ subtree: true }).length,
            };
        })()
    JS);
}

it('loops a showcase demo while it is in view and stops it off screen', function (): void {
    $page = $this->visit('/');

    expect(showcaseLoopState($page, '[data-approval]')['state'])->toBe('idle');

    scrollShowcaseIntoView($page);
    $page->wait(1);

    expect(showcaseLoopState($page, '[data-approval]')['state'])->toBe('playing');

    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.5);

    $state = showcaseLoopState($page, '[data-approval]');

    expect($state['state'])->toBe('idle')
        ->and($state['animations'])->toBe(0);

    $page->assertNoJavaScriptErrors();
});

it('pauses a showcase demo in its finished state and plays it again on the next click', function (): void {
    $page = $this->visit('/');

    scrollShowcaseIntoView($page);
    $page->wait(1)
        ->click('[data-feature-row]:nth-of-type(2) [data-loop-toggle]')
        ->wait(0.5);

    $state = showcaseLoopState($page, '[data-approval]');

    expect($state['state'])->toBe('paused')
        ->and($state['doneShown'])->toBeTrue()
        ->and($state['animations'])->toBe(0)
        ->and($state['toggleLabel'])->toBe('Play the demo');

    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.5);

    expect(showcaseLoopState($page, '[data-approval]')['state'])->toBe('paused');

    scrollShowcaseIntoView($page);
    $page->wait(0.5)
        ->click('[data-feature-row]:nth-of-type(2) [data-loop-toggle]')
        ->wait(0.5);

    $state = showcaseLoopState($page, '[data-approval]');

    expect($state['state'])->toBe('playing')
        ->and($state['toggleLabel'])->toBe('Pause the demo');

    $page->assertNoJavaScriptErrors();
});

it('leaves the board card in the next stage when the demo is paused', function (): void {
    $page = $this->visit('/');

    $page->script("document.querySelector('[data-board]').closest('.feat-card').scrollIntoView({ block: 'center' })");
    $page->wait(1)
        ->click('[data-feature-row]:nth-of-type(1) [data-loop-toggle]')
        ->wait(0.5);

    $state = $page->script(<<<'JS'
        (() => {
            const board = document.querySelector('[data-board]');
            const counts = [...board.querySelectorAll('[data-board-count]')]
                .map((count) => [...count.children].find((span) => ! span.classList.contains('invisible')).textContent.trim());

            return {
                state: board.dataset.loopState,
                moved: board.querySelector('[data-board-card]').style.transform !== '',
                counts,
            };
        })()
    JS);

    expect($state['state'])->toBe('paused')
        ->and($state['moved'])->toBeTrue()
        ->and($state['counts'])->toBe(['1', '2']);

    $page->assertNoJavaScriptErrors();
});

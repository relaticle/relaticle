<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

function heroDemoSnapshot(mixed $page): mixed
{
    return $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('[data-hero-demo]');
            const opacity = (selector) => getComputedStyle(root.querySelector(selector)).opacity;
            const visibility = (selector) => getComputedStyle(root.querySelector(selector)).visibility;

            return {
                state: root.dataset.demoState,
                running: root.getAnimations({ subtree: true }).filter((animation) => ! (animation instanceof CSSAnimation) && animation.playState === 'running').length,
                entry: visibility('.hero-agent-entry'),
                firstReply: opacity('.mcp-text-1'),
                tools: [...root.querySelectorAll('.mcp-tool')].map((tool) => getComputedStyle(tool).opacity),
                dock: visibility('.mcp-dock'),
                input: opacity('.mcp-input'),
                nextSteps: opacity('.mcp-next-steps'),
            };
        })()
    JS);
}

function waitForHeroDemo(mixed $page, string $state, int $seconds = 40): bool
{
    return $page->script(<<<JS
        (async () => {
            const until = Date.now() + {$seconds} * 1000;

            while (Date.now() < until) {
                if (document.querySelector('[data-hero-demo]').dataset.demoState === '{$state}') {
                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 100));
            }

            return false;
        })()
    JS);
}

it('opens on the dashboard and plays every exchange through to the next steps', function (): void {
    $page = $this->visit('/');

    $page->script(<<<'JS'
        window.heroDemoFrames = [];

        const scroller = document.querySelector('[data-hero-demo] [x-ref=messagesScroll]');
        const sample = () => {
            window.heroDemoFrames.push([scroller.clientHeight, scroller.scrollTop]);
            requestAnimationFrame(sample);
        };

        requestAnimationFrame(sample);
    JS);

    $opening = heroDemoSnapshot($page);

    expect($opening['entry'])->toBe('visible')
        ->and($opening['firstReply'])->toBe('0');

    expect(waitForHeroDemo($page, 'done'))->toBeTrue();

    $final = heroDemoSnapshot($page);

    expect($final['entry'])->toBe('hidden')
        ->and($final['firstReply'])->toBe('1')
        ->and($final['tools'])->each->toBe('0')
        ->and($final['dock'])->toBe('hidden')
        ->and($final['input'])->toBe('1')
        ->and($final['nextSteps'])->toBe('1')
        ->and($final['running'])->toBe(0);

    $motion = $page->script(<<<'JS'
        (() => {
            const frames = window.heroDemoFrames;
            const resizes = [];
            let largest = 0;
            let resize = null;

            frames.slice(1).forEach(([height, top], index) => {
                const [previousHeight, previousTop] = frames[index];
                const change = Math.abs(height - previousHeight);

                largest = Math.max(largest, change, Math.abs(top - previousTop));

                if (change === 0) {
                    resize && resizes.push(resize);
                    resize = null;

                    return;
                }

                resize ??= { frames: 0, distance: 0 };
                resize.frames++;
                resize.distance += change;
            });

            return {
                largest,
                shortestResize: Math.min(...resizes.filter((entry) => entry.distance > 20).map((entry) => entry.frames)),
            };
        })()
    JS);

    expect($motion['largest'])->toBeLessThan(80)
        ->and($motion['shortestResize'])->toBeGreaterThanOrEqual(5);

    $page->assertNoJavaScriptErrors();
});

it('stops when another tab opens and replays from the dashboard on return', function (): void {
    $page = $this->visit('/');

    expect(waitForHeroDemo($page, 'playing', 10))->toBeTrue();

    $page->wait(4);

    $page->click('#tab-pipeline');
    $page->wait(1);

    $away = heroDemoSnapshot($page);

    expect($away['state'])->toBe('idle')
        ->and($away['running'])->toBe(0);

    $page->click('#tab-ai-agent');
    $page->wait(1);

    $back = heroDemoSnapshot($page);

    expect($back['state'])->toBe('playing')
        ->and($back['entry'])->toBe('visible')
        ->and($back['firstReply'])->toBe('0');

    $page->assertNoJavaScriptErrors();
});

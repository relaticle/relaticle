<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

beforeEach(function (): void {
    $this->withVite();
});

/**
 * @return array{signup: int, login: int, toggle: int, gapToLogo: int, gapToToggle: int, fitsTheScreen: bool}
 */
function siteHeaderLayout(AwaitableWebpage|PendingAwaitablePage $page, int $width, int $height): array
{
    return $page->resize($width, $height)->script(<<<'JS'
        (() => {
            const box = (selector) => document.querySelector(`#main-header ${selector}`).getBoundingClientRect();
            const logo = box('a[aria-label="Relaticle Home"]');
            const signup = box('[data-analytics-intent="signup"][data-analytics-placement="header"]');
            const toggle = box('[x-ref="hamburger"]');

            return {
                signup: Math.round(signup.width),
                login: Math.round(box('[data-analytics-intent="login"]').width),
                toggle: Math.round(toggle.width),
                gapToLogo: Math.round((signup.width ? signup.left : toggle.left) - logo.right),
                gapToToggle: Math.round(toggle.left - signup.right),
                fitsTheScreen: document.documentElement.scrollWidth <= window.innerWidth,
            };
        })()
    JS);
}

it('keeps the sign-up button in the header beside the menu toggle on a phone', function (): void {
    $page = $this->visit('/');

    foreach ([360, 390] as $width) {
        $header = siteHeaderLayout($page, $width, 844);

        expect($header['signup'])->toBeGreaterThan(0, "at {$width}px")
            ->and($header['login'])->toBe(0, "at {$width}px")
            ->and($header['gapToLogo'])->toBeGreaterThanOrEqual(8, "at {$width}px")
            ->and($header['gapToToggle'])->toBeGreaterThanOrEqual(8, "at {$width}px")
            ->and($header['fitsTheScreen'])->toBeTrue("at {$width}px");
    }
});

it('leaves the header to the logo and the menu toggle below 360 pixels', function (): void {
    $header = siteHeaderLayout($this->visit('/'), 320, 700);

    expect($header['signup'])->toBe(0)
        ->and($header['toggle'])->toBeGreaterThan(0)
        ->and($header['gapToLogo'])->toBeGreaterThanOrEqual(8)
        ->and($header['fitsTheScreen'])->toBeTrue();
});

it('shows sign in and sign up in the header on a desktop', function (): void {
    $header = siteHeaderLayout($this->visit('/'), 1440, 900);

    expect($header['signup'])->toBeGreaterThan(0)
        ->and($header['login'])->toBeGreaterThan(0)
        ->and($header['toggle'])->toBe(0);
});

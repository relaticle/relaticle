<?php

declare(strict_types=1);

use App\Mail\TaskAssignedMail;

function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = [];

    foreach ([0, 2, 4] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $foreground, string $background): float
{
    $light = max(relativeLuminance($foreground), relativeLuminance($background));
    $dark = min(relativeLuminance($foreground), relativeLuminance($background));

    return ($light + 0.05) / ($dark + 0.05);
}

function inlineStyleOf(string $html, string $openingTagPattern, string $property): string
{
    preg_match($openingTagPattern, $html, $tag);
    preg_match('/(?<![\w-])'.preg_quote($property, '/').':\s*(#[0-9a-fA-F]{6})/', $tag[0] ?? '', $value);

    return strtolower($value[1] ?? '');
}

function darkRule(string $html, string $selector, string $property): string
{
    preg_match('/@media \(prefers-color-scheme: dark\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s', $html, $block);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $block[1] ?? '', $rules, PREG_SET_ORDER);

    foreach ($rules as $rule) {
        $selectors = array_map(trim(...), explode(',', $rule[1]));

        if (! in_array($selector, $selectors, true)) {
            continue;
        }

        if (preg_match('/(?<![\w-])'.preg_quote($property, '/').':\s*(#[0-9a-fA-F]{6})/', $rule[2], $value) === 1) {
            return strtolower($value[1]);
        }
    }

    return '';
}

beforeEach(function (): void {
    $this->html = (new TaskAssignedMail('Call client', 'https://app.relaticle.test/tasks/1'))->render();
});

it('meets 4.5:1 for every light-mode text and background pair', function (): void {
    $card = inlineStyleOf($this->html, '/<table[^>]*class="inner-body"[^>]*>/', 'background-color');
    $canvas = inlineStyleOf($this->html, '/<table[^>]*class="wrapper"[^>]*>/', 'background-color');
    $button = inlineStyleOf($this->html, '/<a[^>]*class="button button-primary"[^>]*>/', 'background-color');

    $pairs = [
        [inlineStyleOf($this->html, '/<h1[^>]*>/', 'color'), $card],
        [inlineStyleOf($this->html, '/<p[^>]*>/', 'color'), $card],
        [inlineStyleOf($this->html, '/<a[^>]*class="button button-primary"[^>]*>/', 'color'), $button],
        [inlineStyleOf($this->html, '/<td[^>]*class="footer-cell"[^>]*>/', 'color'), $canvas],
    ];

    foreach ($pairs as [$foreground, $background]) {
        expect($foreground)->toMatch('/^#[0-9a-f]{6}$/')
            ->and($background)->toMatch('/^#[0-9a-f]{6}$/')
            ->and(contrastRatio($foreground, $background))->toBeGreaterThanOrEqual(4.5);
    }
});

it('meets 4.5:1 for every dark-mode text and background pair', function (): void {
    $card = darkRule($this->html, '.inner-body', 'background-color');
    $canvas = darkRule($this->html, '.wrapper', 'background-color');
    $button = darkRule($this->html, '.button-primary', 'background-color');
    $panel = darkRule($this->html, '.panel-content', 'background-color');

    $pairs = [
        [darkRule($this->html, 'body', 'color'), $card],
        [darkRule($this->html, 'h1', 'color'), $card],
        [darkRule($this->html, 'p', 'color'), $card],
        [darkRule($this->html, 'li', 'color'), $card],
        [darkRule($this->html, 'td', 'color'), $card],
        [darkRule($this->html, '.table th', 'color'), $card],
        [darkRule($this->html, '.list-meta', 'color'), $card],
        [darkRule($this->html, '.button-primary', 'color'), $button],
        [darkRule($this->html, '.panel-content', 'color'), $panel],
        [darkRule($this->html, '.footer-cell', 'color'), $canvas],
    ];

    foreach ($pairs as [$foreground, $background]) {
        expect($foreground)->toMatch('/^#[0-9a-f]{6}$/')
            ->and($background)->toMatch('/^#[0-9a-f]{6}$/')
            ->and(contrastRatio($foreground, $background))->toBeGreaterThanOrEqual(4.5);
    }
});

it('mirrors every dark-mode surface in the outlook data-ogsc rules', function (): void {
    preg_match('/@media \(prefers-color-scheme: dark\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s', $this->html, $block);
    preg_match_all('/([^{}]+)\{/', $block[1] ?? '', $media);
    preg_match_all('/\[data-ogsc\]\s*([^{},]+)/', $this->html, $ogsc);

    $mediaSelectors = collect($media[1])->flatMap(fn (string $list): array => array_map(trim(...), explode(',', $list)))->unique();
    $ogscSelectors = collect($ogsc[1])->map(fn (string $selector): string => trim($selector))->unique();

    expect($mediaSelectors->diff($ogscSelectors)->values()->all())->toBe([]);
});

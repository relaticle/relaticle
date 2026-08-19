<?php

declare(strict_types=1);

use App\Support\MarketingNavigation;

mutates(MarketingNavigation::class);

function extractNavRegion(string $html, string $ariaLabel): string
{
    preg_match('/<nav[^>]*aria-label="'.preg_quote($ariaLabel, '/').'"[^>]*>.*?<\/nav>/s', $html, $matches);

    return $matches[0] ?? '';
}

it('links every declared comparison and alternatives page from the footer', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();

    foreach (config('comparisons.compare') as $slug) {
        expect($html)->toContain(route('compare.show', ['competitor' => $slug]));
    }

    foreach (config('comparisons.alternatives') as $slug) {
        expect($html)->toContain(route('alternatives.show', ['competitor' => $slug]));
    }
});

it('renders the four footer columns with labelled navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(__('Product'))
        ->and($html)->toContain(__('Resources'))
        ->and($html)->toContain(__('Compare'))
        ->and($html)->toContain(__('Company'))
        ->and($html)->toContain('aria-label="'.__('Footer').'"');
});

it('links llms.txt and the GitHub repository from the footer', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(route('llms-txt'))
        ->and($html)->toContain('https://github.com/relaticle/relaticle');
});

it('renders the same items in desktop and mobile navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-label="'.__('Main').'"')
        ->and($html)->toContain('aria-label="'.__('Mobile menu').'"')
        ->and(substr_count($html, route('discord')))->toBeGreaterThanOrEqual(3);
});

it('marks external header links with rel noopener', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    $header = extractNavRegion($html, __('Main'));

    preg_match('/<a[^>]*href="'.preg_quote(route('discord'), '/').'"[^>]*>/s', $header, $discordAnchor);

    expect($header)->not->toBe('')
        ->and($discordAnchor[0] ?? '')->toContain('rel="noopener noreferrer"');
});

it('marks the current page in the navigation', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();
    $header = extractNavRegion($html, __('Main'));

    expect($header)->not->toBe('')
        ->and($header)->toContain('aria-current="page"');
});

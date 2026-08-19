<?php

declare(strict_types=1);

use App\Support\MarketingNavigation;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

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

it('names the assistant from config in both the header and the footer', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'Testbot'))->toBeGreaterThanOrEqual(3)
        ->and($html)->not->toContain('>Rela<');
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

it('renders product and resources dropdown groups with new page links', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-expanded')
        ->and($html)->toContain(route('ai'))
        ->and($html)->toContain(route('selfHosted'))
        ->and($html)->toContain(__('Compare'));
});

it('keeps the star count to the hero, not the header', function (): void {
    Cache::put('github_stars_Relaticle_relaticle', 1517, 60);

    $html = $this->get('/')->assertOk()->getContent();

    // The hero carries the social proof. A second occurrence would mean the
    // header badge came back, which duplicates it in the sticky chrome.
    expect(substr_count($html, '1.5K'))->toBe(1);
});

it('drops nav groups that feature flags emptied instead of rendering a dead link', function (): void {
    // With both content flags off, the Resources dropdown's "Learn" group has no
    // children and no url of its own, so an unfiltered group renders <a href="">.
    config()->set('relaticle.features.documentation', false);
    config()->set('relaticle.features.blog', false);
    Feature::purge();

    $groups = app(MarketingNavigation::class)->header();

    foreach ($groups as $group) {
        foreach ($group->children as $child) {
            expect($child->url !== null || $child->children !== [])->toBeTrue();
        }
    }
});

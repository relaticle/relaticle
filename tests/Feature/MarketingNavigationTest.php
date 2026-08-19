<?php

declare(strict_types=1);

use App\Support\MarketingNavigation;

mutates(MarketingNavigation::class);

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

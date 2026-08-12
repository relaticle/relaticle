<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocsRepository;

it('parses a content file into a page keyed by its path', function (): void {
    $page = app(DocsRepository::class)->find('help/getting-started/create-your-first-company');

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Create your first company')
        ->and($page->area)->toBe('help')
        ->and($page->category)->toBe('getting-started')
        ->and($page->order)->toBe(1)
        ->and($page->body)->not->toContain('---')
        ->and($page->updated?->format('Y-m-d'))->toBe('2026-08-12');
});

it('parses a category _index file', function (): void {
    $category = app(DocsRepository::class)->findCategory('help/getting-started');

    expect($category)->not->toBeNull()
        ->and($category->title)->not->toBeEmpty()
        ->and($category->description)->not->toBeEmpty();
});

it('orders pages within a category by their order key', function (): void {
    $orders = app(DocsRepository::class)->pagesIn('help/getting-started')->map(fn ($p): int => $p->order)->values()->all();

    expect($orders)->toBe(array_values(array_unique($orders)))
        ->and($orders)->toBe(collect($orders)->sort()->values()->all());
});

it('does not expose _index files as pages', function (): void {
    expect(app(DocsRepository::class)->pages()->keys()->filter(fn (string $k): bool => str_contains($k, '_index')))->toBeEmpty();
});

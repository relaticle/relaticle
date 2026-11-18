<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;

mutates(DocsRepository::class);

it('has every English page in :dataset with title and description', function (string $locale): void {
    $repo = app(DocsRepository::class);

    $missing = $repo->pages('en')
        ->reject(fn (DocPage $english): bool => $repo->find($english->path, $locale) instanceof DocPage)
        ->map(fn (DocPage $english): string => $english->path)
        ->merge($repo->categories('en')
            ->reject(fn (DocCategory $english): bool => $repo->findCategory($english->path, $locale) instanceof DocCategory)
            ->map(fn (DocCategory $english): string => $english->path))
        ->values();

    expect($missing)->toBeEmpty("Missing from content/{$locale}:\n".$missing->implode("\n"));
})->with('translated locales');

it('has no page in :dataset that English lacks', function (string $locale): void {
    $repo = app(DocsRepository::class);

    $orphans = $repo->pages($locale)->keys()->diff($repo->pages('en')->keys())->values();

    expect($orphans)->toBeEmpty("Not in the English tree:\n".$orphans->implode("\n"));
})->with('translated locales');

<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;

mutates(DocsRepository::class);

/** @param Collection<int, string> $offenders */
function offendersMessage(string $label, Collection $offenders): string
{
    return "{$label}:\n".$offenders->implode("\n");
}

it('resolves every related entry to a real page', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(fn (DocPage $page): array => collect($page->related)
        ->reject(fn (string $related): bool => $repo->find($related) instanceof DocPage)
        ->map(fn (string $related): string => "{$page->path} -> {$related}")
        ->all());

    expect($broken)->toBeEmpty(offendersMessage('Unresolved related: entries', $broken));
});

it('resolves every internal link to a real page', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(function (DocPage $page) use ($repo): array {
        preg_match_all('/\]\((\/help\/[a-z0-9\-\/]+)\)/', $page->body, $matches);

        return collect($matches[1])
            ->reject(fn (string $url): bool => $repo->find(ltrim($url, '/')) instanceof DocPage)
            ->map(fn (string $url): string => "{$page->path} -> {$url}")
            ->all();
    });

    expect($broken)->toBeEmpty(offendersMessage('Broken internal /help links', $broken));
});

it('resolves every referenced image to a file on disk', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(function (DocPage $page): array {
        preg_match_all('/!\[[^\]]*\]\((\/[^)]+)\)/', $page->body, $matches);

        return collect($matches[1])
            ->reject(fn (string $src): bool => file_exists(public_path(ltrim($src, '/'))))
            ->map(fn (string $src): string => "{$page->path} -> {$src}")
            ->all();
    });

    expect($broken)->toBeEmpty(offendersMessage('Referenced images missing from disk', $broken));
});

it('gives every page a unique, length-bounded title and description', function (): void {
    $pages = app(DocsRepository::class)->pages();

    $duplicateTitles = $pages->pluck('title')->duplicates();
    $duplicateTitleOffenders = $pages
        ->filter(fn (DocPage $page): bool => $duplicateTitles->contains($page->title))
        ->map(fn (DocPage $page): string => "{$page->path} -> \"{$page->title}\"")
        ->values();

    $duplicateDescriptions = $pages->pluck('description')->duplicates();
    $duplicateDescriptionOffenders = $pages
        ->filter(fn (DocPage $page): bool => $duplicateDescriptions->contains($page->description))
        ->map(fn (DocPage $page): string => "{$page->path} -> \"{$page->description}\"")
        ->values();

    $overLongTitles = $pages
        ->filter(fn (DocPage $page): bool => mb_strlen($page->title) > 60)
        ->map(fn (DocPage $page): string => "{$page->path} -> {$page->title} (".mb_strlen($page->title).' chars)')
        ->values();

    $overLongDescriptions = $pages
        ->filter(fn (DocPage $page): bool => mb_strlen($page->description) > 160)
        ->map(fn (DocPage $page): string => "{$page->path} -> {$page->description} (".mb_strlen($page->description).' chars)')
        ->values();

    expect($duplicateTitleOffenders)->toBeEmpty(offendersMessage('Duplicate titles', $duplicateTitleOffenders))
        ->and($duplicateDescriptionOffenders)->toBeEmpty(offendersMessage('Duplicate descriptions', $duplicateDescriptionOffenders))
        ->and($overLongTitles)->toBeEmpty(offendersMessage('Titles over 60 chars', $overLongTitles))
        ->and($overLongDescriptions)->toBeEmpty(offendersMessage('Descriptions over 160 chars', $overLongDescriptions));
});

it('gives every page exactly one h1-equivalent, which is its front-matter title', function (): void {
    $offenders = app(DocsRepository::class)->pages()
        ->filter(fn (DocPage $page): bool => (bool) preg_match('/^# /m', $page->body))
        ->keys();

    expect($offenders)->toBeEmpty(offendersMessage('Pages with a stray "# " heading in the body', $offenders));
});

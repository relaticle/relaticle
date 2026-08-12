<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\HeadingAnchors;

mutates(DocsRepository::class, HeadingAnchors::class);

/** @param Collection<int, string> $offenders */
function offendersMessage(string $label, Collection $offenders): string
{
    return "{$label}:\n".$offenders->implode("\n");
}

/**
 * The h1 guard below deliberately fires on any line starting with "# " --
 * except inside a fenced code block, where that's a shell comment or a
 * markdown example, not a real heading. Mirrors the fence-stripping already
 * used by BuildSearchIndex::plainText() so both treat fences the same way.
 */
function withoutFencedCodeBlocks(string $markdown): string
{
    return preg_replace('/```.*?```/s', '', $markdown) ?? $markdown;
}

it('resolves every related entry to a real page', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(fn (DocPage $page): array => collect($page->related)
        ->reject(fn (string $related): bool => $repo->find($related) instanceof DocPage)
        ->map(fn (string $related): string => "{$page->path} -> {$related}")
        ->all());

    expect($broken)->toBeEmpty(offendersMessage('Unresolved related: entries', $broken));
});

it('resolves every internal link -- including anchored and query-qualified ones -- to a real page and heading', function (): void {
    $repo = app(DocsRepository::class);
    $headingAnchors = new HeadingAnchors;

    $broken = $repo->pages()->flatMap(function (DocPage $page) use ($repo, $headingAnchors): array {
        // Path first, then an optional `?query` (order doesn't matter for a
        // real URL, but query-before-fragment is the only shape a browser
        // would ever produce), then an optional `#fragment` -- both must be
        // consumed before the closing `)`, or a link like
        // `/help/a/b#section` never matches at all.
        preg_match_all('/\]\((\/help\/[a-z0-9\-\/]+)(?:\?[^)#]*)?(?:#([^)]*))?\)/u', $page->body, $matches, PREG_SET_ORDER);

        return collect($matches)->flatMap(function (array $match) use ($page, $repo, $headingAnchors): array {
            $url = $match[1];
            $fragment = $match[2] ?? '';
            $target = $repo->find(ltrim($url, '/'));

            if (! $target instanceof DocPage) {
                return ["{$page->path} -> {$url}"];
            }

            if ($fragment !== '' && ! in_array($fragment, $headingAnchors($target->body), true)) {
                return ["{$page->path} -> {$url}#{$fragment}"];
            }

            return [];
        })->all();
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
        ->filter(fn (DocPage $page): bool => (bool) preg_match('/^# /m', withoutFencedCodeBlocks($page->body)))
        ->keys();

    expect($offenders)->toBeEmpty(offendersMessage('Pages with a stray "# " heading in the body', $offenders));
});

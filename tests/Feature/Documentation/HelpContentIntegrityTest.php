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

function renderedTitleBudgetAfterBrandSuffix(): int
{
    return 60 - mb_strlen(' - '.config('app.name'));
}

it('resolves every related entry to a real page', function (string $locale): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages($locale)->flatMap(fn (DocPage $page): array => collect($page->related)
        ->reject(fn (string $related): bool => $repo->find($related, $locale) instanceof DocPage)
        ->map(fn (string $related): string => "{$page->path} -> {$related}")
        ->all());

    expect($broken)->toBeEmpty(offendersMessage('Unresolved related: entries', $broken));
})->with('content locales');

it('resolves every internal link -- including anchored and query-qualified ones -- to a real page and heading', function (string $locale): void {
    $repo = app(DocsRepository::class);
    $headingAnchors = new HeadingAnchors;
    $prefix = $locale === 'en' ? '' : "/{$locale}";
    $prefixes = implode('|', array_diff(config('app.available_locales'), ['en']));

    $broken = $repo->pages($locale)->flatMap(function (DocPage $page) use ($repo, $headingAnchors, $locale, $prefix, $prefixes): array {
        // Prefix first, then the path, an optional `?query` and an optional
        // `#fragment`, all consumed before the closing `)`, or a link like
        // `/help/a/b#section` never matches at all.
        preg_match_all('/\]\(((?:\/(?:'.$prefixes.'))?)(\/(?:help|developers)(?:\/[a-z0-9\-]+)*)(?:\?[^)#]*)?(?:#([^)]*))?\)/u', $page->body, $matches, PREG_SET_ORDER);

        return collect($matches)->flatMap(function (array $match) use ($page, $repo, $headingAnchors, $locale, $prefix): array {
            $url = $match[1].$match[2];
            $fragment = $match[3] ?? '';

            if ($match[2] === '/developers/api') {
                return $match[1] === '' ? [] : ["{$page->path} -> {$url} (the API reference has one unprefixed URL)"];
            }

            if ($match[1] !== $prefix) {
                return ["{$page->path} -> {$url} (must start with ".($prefix === '' ? 'no language prefix' : "{$prefix}/").')'];
            }

            $path = ltrim($match[2], '/');
            $path = str_starts_with($path, 'developers/') ? 'docs/guides/'.substr($path, strlen('developers/')) : $path;
            $target = $repo->find($path, $locale);

            if (! $target instanceof DocPage && $fragment === '' && ($match[2] === '/developers' || $match[2] === '/help' || $repo->findCategory($path, $locale) !== null)) {
                return [];
            }

            if (! $target instanceof DocPage) {
                return ["{$page->path} -> {$url}"];
            }

            if ($fragment !== '' && ! in_array($fragment, $headingAnchors($target->body), true)) {
                return ["{$page->path} -> {$url}#{$fragment}"];
            }

            return [];
        })->all();
    });

    expect($broken)->toBeEmpty(offendersMessage('Broken internal /help and /developers links', $broken));
})->with('content locales');

it('resolves every same-page anchor link to a heading of that page', function (string $locale): void {
    $headingAnchors = new HeadingAnchors;

    $broken = app(DocsRepository::class)->pages($locale)->flatMap(function (DocPage $page) use ($headingAnchors): array {
        preg_match_all('/\]\(#([^)\s]*)\)/u', withoutFencedCodeBlocks($page->body), $matches);
        $anchors = $headingAnchors($page->body);

        return collect($matches[1])
            ->reject(fn (string $fragment): bool => in_array(urldecode($fragment), $anchors, true))
            ->map(fn (string $fragment): string => "{$page->path} -> #{$fragment}")
            ->all();
    });

    expect($broken)->toBeEmpty(offendersMessage('Same-page anchors with no matching heading', $broken));
})->with('content locales');

it('resolves every referenced image to a file on disk', function (string $locale): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages($locale)->flatMap(function (DocPage $page): array {
        preg_match_all('/!\[[^\]]*\]\((\/[^)]+)\)/', $page->body, $matches);

        return collect($matches[1])
            ->reject(fn (string $src): bool => file_exists(public_path(ltrim($src, '/'))))
            ->map(fn (string $src): string => "{$page->path} -> {$src}")
            ->all();
    });

    expect($broken)->toBeEmpty(offendersMessage('Referenced images missing from disk', $broken));
})->with('content locales');

it('gives every page a unique, length-bounded title and description', function (string $locale): void {
    $pages = app(DocsRepository::class)->pages($locale);

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

    $titleBudget = renderedTitleBudgetAfterBrandSuffix();

    $overLongTitles = $pages
        ->filter(fn (DocPage $page): bool => mb_strlen($page->title) > $titleBudget)
        ->map(fn (DocPage $page): string => "{$page->path} -> {$page->title} (".mb_strlen($page->title).' chars)')
        ->values();

    $descriptionsOutOfBounds = $pages
        ->filter(fn (DocPage $page): bool => mb_strlen($page->description) < 70 || mb_strlen($page->description) > 160)
        ->map(fn (DocPage $page): string => "{$page->path} -> {$page->description} (".mb_strlen($page->description).' chars)')
        ->values();

    expect($duplicateTitleOffenders)->toBeEmpty(offendersMessage('Duplicate titles', $duplicateTitleOffenders))
        ->and($duplicateDescriptionOffenders)->toBeEmpty(offendersMessage('Duplicate descriptions', $duplicateDescriptionOffenders))
        ->and($overLongTitles)->toBeEmpty(offendersMessage("Titles over {$titleBudget} chars", $overLongTitles))
        ->and($descriptionsOutOfBounds)->toBeEmpty(offendersMessage('Descriptions outside 70 to 160 chars', $descriptionsOutOfBounds));
})->with('content locales');

it('gives every category a unique, length-bounded title and description, distinct from every page', function (string $locale): void {
    $repo = app(DocsRepository::class);
    $categories = $repo->categories($locale);

    $titleBudget = renderedTitleBudgetAfterBrandSuffix();

    $overLong = $categories
        ->filter(fn ($category): bool => mb_strlen($category->title) > $titleBudget || mb_strlen($category->description) < 70 || mb_strlen($category->description) > 160)
        ->map(fn ($category): string => "{$category->path} (title ".mb_strlen($category->title).', desc '.mb_strlen($category->description).')')
        ->values();

    // Categories render as pages (hub sections, <title> tags, sitemap URLs),
    // so their metadata competes in the same SERPs as articles -- uniqueness
    // has to hold across both sets, not within each.
    $titles = $repo->pages($locale)->pluck('title')->merge($categories->pluck('title'));
    $descriptions = $repo->pages($locale)->pluck('description')->merge($categories->pluck('description'));

    expect($overLong)->toBeEmpty(offendersMessage('Category metadata over bounds', $overLong))
        ->and($titles->duplicates()->values()->all())->toBe([])
        ->and($descriptions->duplicates()->values()->all())->toBe([]);
})->with('content locales');

it('gives every page exactly one h1-equivalent, which is its front-matter title', function (string $locale): void {
    $offenders = app(DocsRepository::class)->pages($locale)
        ->filter(fn (DocPage $page): bool => (bool) preg_match('/^# /m', withoutFencedCodeBlocks($page->body)))
        ->keys();

    expect($offenders)->toBeEmpty(offendersMessage('Pages with a stray "# " heading in the body', $offenders));
})->with('content locales');

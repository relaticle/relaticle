<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Relaticle\Documentation\Http\Controllers\HelpController;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocsJsonLd;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\DocUrl;
use Relaticle\Documentation\Support\HeadingAnchors;
use Relaticle\Documentation\Support\RenderDocMarkdown;

mutates(HelpController::class, DocsJsonLd::class, BuildSearchIndex::class, HeadingAnchors::class);

/** @return list<string> Real heading-permalink ids, in document order, extracted from rendered HTML. */
function renderedHeadingIds(string $html): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
    libxml_clear_errors();

    $ids = [];

    foreach ((new DOMXPath($dom))->query('//a[contains(concat(" ", normalize-space(@class), " "), " heading-permalink ")]') as $node) {
        /** @var DOMElement $node */
        $ids[] = $node->getAttribute('id');
    }

    return $ids;
}

it('emits article and breadcrumb json-ld on a help article', function (): void {
    $html = $this->get('/help/getting-started/create-your-first-company')->assertOk()->getContent();
    $url = route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']);

    expect($html)->toContain('"@type":"Article"')
        ->and($html)->toContain('"@type":"BreadcrumbList"')
        ->and($html)->toContain('"headline":"Create your first company"')
        ->and($html)->toContain('"description":"Add a company record and fill in the fields your team actually uses."')
        ->and($html)->toContain('"mainEntityOfPage":"'.$url.'"')
        ->and($html)->toContain('"position":1')
        ->and($html)->toContain('"position":2')
        ->and($html)->toContain('"position":3');
});

it('emits breadcrumb json-ld on the help hub and category pages', function (): void {
    $hub = $this->get('/help')->assertOk()->getContent();
    $category = $this->get('/help/getting-started')->assertOk()->getContent();

    expect($hub)->toContain('"@type":"BreadcrumbList"')
        ->and($hub)->not->toContain('"@type":"Article"')
        ->and($category)->toContain('"@type":"BreadcrumbList"')
        ->and($category)->not->toContain('"@type":"Article"');
});

it('serves a section-level search index', function (): void {
    $payload = $this->get('/help/search-index.json')->assertOk()->json();

    expect($payload['v'])->toBe(2)
        ->and($payload['records'])->not->toBeEmpty()
        ->and($payload['records'][0])->toHaveKeys(['path', 'title', 'section', 'anchor', 'content']);

    $sections = collect($payload['records'])->pluck('section');

    expect($sections)->toContain('Create your first company')
        ->and($sections)->toContain("If you don't see the fields you expect");

    $headingRecord = collect($payload['records'])
        ->first(fn (array $record): bool => $record['path'] === 'help/getting-started/create-your-first-company'
            && $record['section'] === "If you don't see the fields you expect");

    expect($headingRecord['anchor'])->toBe('if-you-dont-see-the-fields-you-expect')
        ->and($headingRecord['path'])->toBe('help/getting-started/create-your-first-company')
        ->and($headingRecord['content'])->toContain('Custom Fields');
});

it('indexes the developer guides alongside help, with a link and a crumb per record', function (): void {
    $records = collect($this->get('/help/search-index.json')->assertOk()->json('records'));

    $guide = $records->first(fn (array $record): bool => $record['path'] === 'docs/guides/mcp' && $record['anchor'] === '');
    $article = $records->first(fn (array $record): bool => $record['path'] === 'help/getting-started/create-your-first-company' && $record['anchor'] === '');

    expect($guide)->not->toBeNull()
        ->and($guide['url'])->toBe(route('documentation.show', ['type' => 'mcp']))
        ->and($guide['crumb'])->toBe('Developers')
        ->and($article['url'])->toBe(route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']))
        ->and($article['crumb'])->toBe('Getting started');

    $section = $records->first(fn (array $record): bool => $record['path'] === 'help/getting-started/create-your-first-company'
        && $record['section'] === "If you don't see the fields you expect");

    expect($section['url'])->toEndWith('#if-you-dont-see-the-fields-you-expect');
});

it('titles every content page base-title-dash-brand, exactly once', function (): void {
    // Shiki would spawn a node process per fenced block across all 36 pages;
    // nothing here reads highlighted output.
    config()->set('markdown.code_highlighting.enabled', false);

    $brand = config('app.name');
    $offenders = [];

    foreach (app(DocsRepository::class)->pages() as $page) {
        $html = $this->get(DocUrl::page($page))->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/', $html, $match);

        $expected = "{$page->title} - {$brand}";

        if (($match[1] ?? null) !== $expected || substr_count($match[1] ?? '', " - {$brand}") !== 1) {
            $offenders[] = "{$page->path} -> ".($match[1] ?? '(no title)');
        }
    }

    expect($offenders)->toBe([]);
});

it('lazy-loads article images', function (): void {
    $html = $this->get('/help/records/company-records')->assertOk()->getContent();

    expect($html)->toContain('<img loading="lazy" decoding="async" src="/help-assets/records/company-records-1.png"');
});

it('serves an llms.txt indexing help and docs', function (): void {
    $response = $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');

    $body = $response->getContent();

    expect($body)->toContain('/help/getting-started/create-your-first-company')
        ->and($body)->toContain('/developers/self-hosting')
        ->and($body)->toContain('Help Centre')
        ->and($body)->toContain('Documentation')
        ->and($body)->toContain('/compare/relaticle-vs-twenty')
        ->and($body)->toContain('/compare/relaticle-vs-espocrm')
        ->and($body)->toContain('/alternatives/attio')
        ->and($body)->toContain('/alternatives/hubspot')
        ->and($body)->toContain('/press');
});

it('tells agents when to use Relaticle and where the machine-readable surfaces are', function (): void {
    $body = $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain('## When to use Relaticle')
        ->and($body)->toContain('Not a fit:')
        ->and($body)->toContain(url()->getApiUrl('v1'))
        ->and($body)->toContain(route('openapi.json'))
        ->and($body)->toContain(url()->getMcpUrl());
});

it('lists the product pages in llms.txt', function (): void {
    $body = $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain(route('ai'))
        ->and($body)->toContain(route('selfHosted'))
        ->and($body)->toContain(config('chat.assistant_name'));
});

it('gives the search index anchors that equal the real heading ids the renderer emits', function (): void {
    $fixturePath = storage_path('framework/testing/search-index-anchors-'.Str::random(8));

    File::ensureDirectoryExists("{$fixturePath}/help/anchors");
    File::put(
        "{$fixturePath}/help/anchors/duplicate-and-unicode-headings.md",
        <<<'MARKDOWN'
        ---
        title: Duplicate and unicode headings
        description: Exercises duplicate and non-ASCII heading anchors.
        order: 1
        ---

        Intro text above the first heading.

        ## Settings

        First settings section.

        ## Café Settings

        A non-ASCII heading -- must keep its Unicode letters, not transliterate them.

        ## Settings

        A second heading with the same text as the first, forcing the
        renderer to append a disambiguating suffix.
        MARKDOWN,
    );

    Config::set('documentation.content_path', $fixturePath);

    $repository = new DocsRepository;
    $page = $repository->find('help/anchors/duplicate-and-unicode-headings');

    expect($page)->not->toBeNull();

    $renderedIds = renderedHeadingIds(app(RenderDocMarkdown::class)($page->body));

    $indexAnchors = collect((new BuildSearchIndex($repository))()['records'])
        ->where('path', $page->path)
        ->pluck('anchor')
        ->reject(fn (string $anchor): bool => $anchor === '')
        ->values()
        ->all();

    File::deleteDirectory($fixturePath);

    expect($renderedIds)->toBe(['settings', 'café-settings', 'settings-1'])
        ->and($indexAnchors)->toBe($renderedIds);
});

it('busts the cached search index on a front-matter-only title edit', function (): void {
    $bodyTemplate = <<<'MARKDOWN'
        ---
        title: %s
        description: Exercises the search-index cache signature.
        order: 1
        ---

        Same body both times, only the title changes.
        MARKDOWN;

    $original = storage_path('framework/testing/search-index-cache-original-'.Str::random(8));
    File::ensureDirectoryExists("{$original}/help/cache-guard");
    File::put("{$original}/help/cache-guard/page.md", sprintf($bodyTemplate, 'Original title'));

    $updated = storage_path('framework/testing/search-index-cache-updated-'.Str::random(8));
    File::ensureDirectoryExists("{$updated}/help/cache-guard");
    File::put("{$updated}/help/cache-guard/page.md", sprintf($bodyTemplate, 'Updated title'));

    Config::set('documentation.content_path', $original);
    $firstRecord = collect((new BuildSearchIndex(new DocsRepository))()['records'])
        ->firstWhere('path', 'help/cache-guard/page');

    Config::set('documentation.content_path', $updated);
    $secondRecord = collect((new BuildSearchIndex(new DocsRepository))()['records'])
        ->firstWhere('path', 'help/cache-guard/page');

    File::deleteDirectory($original);
    File::deleteDirectory($updated);

    expect($firstRecord['title'])->toBe('Original title')
        ->and($secondRecord['title'])->toBe('Updated title');
});

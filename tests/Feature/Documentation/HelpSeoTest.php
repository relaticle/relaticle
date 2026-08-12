<?php

declare(strict_types=1);

use Relaticle\Documentation\Http\Controllers\HelpController;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocsJsonLd;

mutates(HelpController::class, DocsJsonLd::class, BuildSearchIndex::class);

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

it('serves an llms.txt indexing help and docs', function (): void {
    $response = $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');

    $body = $response->getContent();

    expect($body)->toContain('/help/getting-started/create-your-first-company')
        ->and($body)->toContain('/docs/getting-started')
        ->and($body)->toContain('Help Centre')
        ->and($body)->toContain('Documentation');
});

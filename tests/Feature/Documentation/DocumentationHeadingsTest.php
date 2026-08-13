<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\HeadingAnchors;

mutates(HeadingAnchors::class);

it('renders documentation headings without a literal permalink symbol or mangled id', function (): void {
    $html = $this->get('/docs/mcp')->assertOk()->getContent();

    preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $h1);

    expect(trim(strip_tags($h1[1])))->toBe('MCP Server')
        ->and($html)->not->toMatch('/<h1[^>]*id="a-id/')
        ->and($html)->not->toContain('>#</a>');
});

it('points every on-this-page link at a heading id that exists in the rendered article', function (): void {
    $html = $this->get('/docs/mcp')->assertOk()->getContent();

    preg_match('/<ul id="docs-toc".*?<\/ul>/s', $html, $toc);
    preg_match_all('/href="#([^"]+)"/', $toc[0] ?? '', $tocAnchors);
    // Attribute order is the renderer's business, so match on presence.
    preg_match_all('/<a\b(?=[^>]*class="heading-permalink")[^>]*\bid="([^"]+)"/', $html, $rendered);

    expect($tocAnchors[1])->not->toBeEmpty()
        // The rail only lists h2s; every one of them must resolve to a real
        // anchor, or "on this page" scrolls nowhere.
        ->and(array_diff($tocAnchors[1], $rendered[1]))->toBeEmpty();
});

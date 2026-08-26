<?php

declare(strict_types=1);

use App\Support\DetectsPublicMarkdownRequest;
use Relaticle\Documentation\Http\Controllers\DocumentationController;
use Relaticle\Documentation\Support\DocsNavigation;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\HeadingAnchors;
use Relaticle\Documentation\Support\RenderDocMarkdown;

mutates(
    DetectsPublicMarkdownRequest::class,
    DocumentationController::class,
    DocsNavigation::class,
    DocsRepository::class,
    HeadingAnchors::class,
    RenderDocMarkdown::class,
);

it('renders documentation headings without a literal permalink symbol or mangled id', function (): void {
    $html = $this->get('/developers/mcp')->assertOk()->getContent();

    preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $h1);

    expect(trim(strip_tags($h1[1])))->toBe('MCP Server')
        ->and($html)->not->toMatch('/<h1[^>]*id="a-id/')
        ->and($html)->not->toContain('>#</a>');
});

it('points every on-this-page link at a heading id that exists in the rendered article', function (): void {
    $html = $this->get('/developers/mcp')->assertOk()->getContent();

    preg_match('/<ul id="docs-toc".*?<\/ul>/s', $html, $toc);
    preg_match_all('/href="#([^"]+)"/', $toc[0] ?? '', $tocAnchors);
    // Attribute order is the renderer's business, so match on presence.
    preg_match_all('/<a\b(?=[^>]*class="heading-permalink")[^>]*\bid="([^"]+)"/', $html, $rendered);

    expect($tocAnchors[1])->not->toBeEmpty()
        // The rail only lists h2s; every one of them must resolve to a real
        // anchor, or "on this page" scrolls nowhere.
        ->and(array_diff($tocAnchors[1], $rendered[1]))->toBeEmpty();
});

it('states the actual MCP rate limits in the rendered guide as :format', function (array $headers, string $contentType): void {
    $response = $this->get('/developers/mcp', $headers)->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith($contentType);

    $response
        ->assertSee('MCP tool requests are limited to 120 per minute per authenticated user.')
        ->assertSee('OAuth authorization endpoints are limited to 20 per minute per IP address.')
        ->assertDontSee('The MCP server uses the same rate limits as the REST API.');
})->with([
    'HTML' => [[], 'text/html'],
    'Markdown' => [['Accept' => 'text/markdown'], 'text/markdown'],
]);

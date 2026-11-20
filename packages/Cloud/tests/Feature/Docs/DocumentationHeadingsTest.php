<?php

declare(strict_types=1);

use App\Support\DetectsPublicMarkdownRequest;
use Dom\HTMLDocument;
use Relaticle\Cloud\Docs\Http\Controllers\DocumentationController;
use Relaticle\Cloud\Docs\Support\DocHeadingRenderer;
use Relaticle\Cloud\Docs\Support\DocsNavigation;
use Relaticle\Cloud\Docs\Support\RenderDocMarkdown;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\HeadingAnchors;
use Relaticle\Documentation\Support\PageSections;
use Spatie\LaravelMarkdown\MarkdownRenderer;

mutates(
    DetectsPublicMarkdownRequest::class,
    DocumentationController::class,
    DocHeadingRenderer::class,
    DocsNavigation::class,
    DocsRepository::class,
    HeadingAnchors::class,
    PageSections::class,
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

it('resolves section links from each localized landing page and guide contents', function (): void {
    config()->set('markdown.code_highlighting.enabled', false);

    foreach (config('app.available_locales') as $locale) {
        $fragments = [];

        foreach (['selfHosted', 'aiNativeCrm'] as $route) {
            $html = $this->get(LocalizedUrl::route($route, locale: $locale))->assertOk()->getContent();
            $landing = HTMLDocument::createFromString($html, LIBXML_NOERROR);

            foreach ($landing->querySelectorAll('a[href*="/developers/"][href*="#"]') as $link) {
                $url = $link->getAttribute('href');
                $fragment = parse_url($url, PHP_URL_FRAGMENT);
                $path = parse_url($url, PHP_URL_PATH);
                $fragments[] = $fragment;

                expect($path)->toStartWith($locale === 'en' ? '/developers/' : "/{$locale}/developers/");

                $guide = HTMLDocument::createFromString($this->get($path)->assertOk()->getContent(), LIBXML_NOERROR);
                expect($guide->querySelectorAll("[id=\"{$fragment}\"]")->length)->toBe(1, $url);

                foreach ($guide->querySelectorAll('#docs-toc a') as $item) {
                    $anchor = substr($item->getAttribute('href'), 1);
                    expect($guide->getElementById($anchor)?->closest('h2')?->textContent)->toBe(trim($item->textContent));
                }
            }
        }

        expect(array_unique($fragments))->toContain('ai-assistant', 'reverse-proxy-and-ssl', 'schema-resources');
    }
});

it('keeps an alias beside a renamed heading and accepts only documentation IDs', function (): void {
    $markdown = "{#schema-resources .unexpected-class}\n## Ressources renommées";
    $html = app(RenderDocMarkdown::class)($markdown);
    $guide = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($guide->getElementById('schema-resources')?->querySelector('a')?->getAttribute('href'))->toBe('#ressources-renommées')
        ->and($guide->getElementById('ressources-renommées')?->closest('h2')?->textContent)->toBe('Ressources renommées')
        ->and($html)->not->toContain('unexpected-class')
        ->and(app(MarkdownRenderer::class)->toHtml($markdown))->not->toContain('id="schema-resources"');
});

it('keeps guide aliases out of the preceding section in search results', function (): void {
    $records = $this->get('/fr/help/search-index.json')->assertOk()->json('records');
    $sections = collect($records)->where('path', 'docs/guides/mcp');

    expect($sections->pluck('anchor'))->toContain('ressources-de-schéma')
        ->and($sections->pluck('content')->implode(' '))->not->toContain('schema-resources');
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

it('explains OAuth setup for ChatGPT and Claude in the rendered guide as :format', function (array $headers, string $contentType): void {
    $response = $this->get('/developers/mcp', $headers)->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith($contentType);

    $response
        ->assertSee('Clients with OAuth support need only the MCP endpoint.')
        ->assertSee('ChatGPT')
        ->assertSee('Claude')
        ->assertDontSee('Before connecting an AI assistant, you need an access token:');
})->with([
    'HTML' => [[], 'text/html'],
    'Markdown' => [['Accept' => 'text/markdown'], 'text/markdown'],
]);

<?php

declare(strict_types=1);

use App\Http\Controllers\AlternativesController;
use App\Http\Controllers\ComparisonController;
use Symfony\Component\DomCrawler\Crawler;

mutates(AlternativesController::class, ComparisonController::class);

it('renders each declared comparison page with a facts table and dates', function (string $url, string $expect): void {
    $this->get($url)
        ->assertOk()
        ->assertSee($expect)
        ->assertSee(__('Facts verified'));
})->with([
    ['/compare/relaticle-vs-twenty', 'Twenty'],
    ['/compare/relaticle-vs-espocrm', 'EspoCRM'],
    ['/alternatives/attio', 'Attio'],
    ['/alternatives/hubspot', 'HubSpot'],
]);

it('404s for undeclared competitors', function (): void {
    $this->get('/compare/relaticle-vs-salesforce')->assertNotFound();
});

it('serves comparison pages as clean markdown without nav chrome', function (string $url, string $competitor): void {
    $markdown = $this->get($url, ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain($competitor)
        ->and($markdown)->not->toContain('Skip to');
})->with([
    ['/compare/relaticle-vs-twenty', 'Twenty'],
    ['/alternatives/attio', 'Attio'],
]);

it('renders question-shaped headings and primary source links on comparison pages', function (): void {
    $html = $this->get('/compare/relaticle-vs-twenty')->assertOk()->getContent();

    expect($html)->toContain('How do Relaticle and Twenty pricing compare?')
        ->and($html)->toContain('Which one runs AI and MCP self-hosted?')
        ->and($html)->toContain('Twenty CRM')
        ->and($html)->toContain(__('Primary sources:'))
        ->and($html)->toContain('https://twenty.com/pricing')
        ->and($html)->toContain('https://github.com/twentyhq/twenty');
});

it('marks comparison pages with dateModified and about entities in the JSON-LD graph', function (): void {
    $html = $this->get('/compare/relaticle-vs-espocrm')->assertOk()->getContent();

    expect($html)->toContain('"dateModified"')
        ->and($html)->toContain('"about"')
        ->and($html)->toContain('"SoftwareApplication"')
        ->and($html)->toContain('https://github.com/espocrm/espocrm');
});

it('gives each page a search title that leads with open source and stays under 60 characters', function (string $url, string $expected): void {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain("<title>{$expected}</title>")
        ->and($html)->toContain('<meta property="og:title" content="'.$expected.'"')
        ->and(mb_strlen($expected))->toBeLessThanOrEqual(60)
        ->and($expected)->toContain('Open Source');
})->with([
    ['/compare/relaticle-vs-twenty', 'Relaticle vs Twenty: Open Source CRM Compared'],
    ['/compare/relaticle-vs-espocrm', 'Relaticle vs EspoCRM: Open Source CRM Compared'],
    ['/alternatives/attio', 'Open Source Attio Alternative, Self-Hosted | Relaticle'],
    ['/alternatives/hubspot', 'Open Source HubSpot Alternative, Self-Hosted | Relaticle'],
]);

it('keeps the on-page heading short and distinct from the longer search title', function (string $url, string $heading): void {
    $html = $this->get($url)->assertOk()->getContent();

    $headings = (new Crawler($html))->filter('h1');

    expect($headings)->toHaveCount(1)
        ->and($headings->text())->toBe($heading)
        ->and($html)->not->toContain("<title>{$heading} - Relaticle</title>");
})->with([
    ['/compare/relaticle-vs-twenty', 'Relaticle vs Twenty'],
    ['/alternatives/attio', 'An open-source Attio alternative.'],
]);

it('keeps competitor prices out of meta descriptions so the facts file stays the only source', function (string $url): void {
    $html = $this->get($url)->assertOk()->getContent();

    preg_match('/<meta name="description" content="([^"]*)"/', $html, $matches);

    expect($matches[1] ?? '')->not->toBeEmpty()
        ->and($matches[1])->not->toMatch('/\$\d/')
        ->and(mb_strlen($matches[1]))->toBeLessThanOrEqual(160);
})->with([
    '/compare/relaticle-vs-twenty',
    '/compare/relaticle-vs-espocrm',
    '/alternatives/attio',
    '/alternatives/hubspot',
]);

it('does not lowercase the leading acronym of an extensibility fact in alternatives prose', function (): void {
    $html = $this->get('/alternatives/hubspot')->assertOk()->getContent();

    expect($html)->not->toContain('rEST')
        ->and($html)->toContain('Relaticle&#039;s extensibility: REST API plus a 39-tool MCP server');
});

it('keeps Attio FAQ structured data consistent with readable answers', function (): void {
    $crawler = new Crawler($this->get('/alternatives/attio')->assertOk()->getContent());
    $questions = $crawler->filter('#attio-faq details')->each(fn (Crawler $item): array => [
        '@type' => 'Question',
        'name' => $item->filter('summary')->text(),
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $item->filter('p')->text(),
        ],
    ]);
    $schemas = $crawler->filter('script[type="application/ld+json"]')
        ->each(fn (Crawler $script): array => json_decode($script->text(), true, flags: JSON_THROW_ON_ERROR));
    $faq = collect($schemas)->flatMap(fn (array $schema): array => $schema['@graph'] ?? [$schema])
        ->firstWhere('@type', 'FAQPage');

    expect($questions)->not->toBeEmpty()
        ->and($faq['mainEntity'])->toBe($questions);
});

it('offers a trial and self-hosting before the Attio comparison', function (): void {
    $crawler = new Crawler($this->get('/alternatives/attio')->assertOk()->getContent());
    $hero = $crawler->filter('#attio-intro');

    expect($hero->filter('a[href="'.route('login').'"]')->text())->toContain(__('Start for free'))
        ->and($hero->filter('a[href="'.route('selfHosted').'"]')->text())->toContain(__('Explore self-hosting'));
});

it('keeps Attio migration limits and primary sources in the markdown response', function (): void {
    $markdown = $this->get('/alternatives/attio', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Enriched values are excluded')
        ->and($markdown)->toContain('Email history is not included')
        ->and($markdown)->toContain('https://attio.com/help/reference/imports-exports/exporting-lists-and-views')
        ->and($markdown)->toContain('https://attio.com/help/reference/attio-ai/attio-mcp');
});

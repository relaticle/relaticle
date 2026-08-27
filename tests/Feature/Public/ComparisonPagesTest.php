<?php

declare(strict_types=1);

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

it('serves comparison pages as clean markdown without nav chrome', function (): void {
    $markdown = $this->get('/compare/relaticle-vs-twenty', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Twenty')
        ->and($markdown)->not->toContain('Skip to');
});

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

    expect($html)->toContain(">\n                    {$heading}\n                </h1>")
        ->and($html)->not->toContain("<title>{$heading} - Relaticle</title>");
})->with([
    ['/compare/relaticle-vs-twenty', 'Relaticle vs Twenty'],
    ['/alternatives/attio', 'Attio Alternative'],
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
    $html = $this->get('/alternatives/attio')->assertOk()->getContent();

    expect($html)->not->toContain('rEST')
        ->and($html)->toContain('Relaticle&#039;s extensibility: REST API plus a 32-tool MCP server');
});

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

it('does not lowercase the leading acronym of an extensibility fact in alternatives prose', function (): void {
    $html = $this->get('/alternatives/attio')->assertOk()->getContent();

    expect($html)->not->toContain('rEST')
        ->and($html)->toContain('Relaticle&#039;s extensibility: REST API plus a 32-tool MCP server');
});

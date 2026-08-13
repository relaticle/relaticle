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

it('serves comparison pages as clean markdown with the table intact', function (): void {
    $markdown = $this->get('/compare/relaticle-vs-twenty', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Twenty')
        ->and($markdown)->not->toContain('Skip to');
});

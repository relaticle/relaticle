<?php

declare(strict_types=1);

it('redirects the legacy quickstart doc to getting started', function (): void {
    $this->get('/documentation/quickstart')
        ->assertRedirect('/docs/getting-started')
        ->assertStatus(301);
});

it('redirects known legacy doc slugs to their current documentation pages', function (string $legacy, string $target): void {
    $this->get("/documentation/{$legacy}")
        ->assertRedirect($target)
        ->assertStatus(301);
})->with([
    ['getting-started', '/docs/getting-started'],
    ['import', '/docs/import'],
    ['developer', '/docs/developer'],
    ['self-hosting', '/docs/self-hosting'],
    ['mcp', '/docs/mcp'],
    ['api', '/docs/api'],
]);

it('redirects unknown legacy doc slugs to the documentation index', function (): void {
    $this->get('/documentation/some-removed-page')
        ->assertRedirect('/docs')
        ->assertStatus(301);
});

it('redirects the bare legacy documentation url to the documentation index', function (): void {
    $this->get('/documentation')
        ->assertRedirect('/docs')
        ->assertStatus(301);
});

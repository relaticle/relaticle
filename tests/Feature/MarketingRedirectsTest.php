<?php

declare(strict_types=1);

it('redirects both legacy documentation generations straight to their final urls', function (string $legacy, string $target): void {
    // /documentation/* is the oldest generation; /docs/* was retired 2026-08-13
    // when the developer area moved to /developers and its two end-user guides
    // moved into /help. Every hop must be single -- a chain would leak PageRank
    // and slow agents.
    $this->get($legacy)
        ->assertRedirect($target)
        ->assertStatus(301);
})->with([
    ['/documentation/quickstart', '/help/getting-started'],
    ['/documentation/getting-started', '/help/getting-started'],
    ['/documentation/import', '/help/import'],
    ['/documentation/developer', '/developers/contributing'],
    ['/documentation/self-hosting', '/developers/self-hosting'],
    ['/documentation/mcp', '/developers/mcp'],
    ['/documentation/api', '/developers/api'],
    ['/docs/getting-started', '/help/getting-started'],
    ['/docs/import', '/help/import'],
    ['/docs/developer', '/developers/contributing'],
    ['/docs/self-hosting', '/developers/self-hosting'],
    ['/docs/mcp', '/developers/mcp'],
    ['/docs/api', '/developers/api'],
]);

it('redirects unknown legacy doc slugs to the developers index', function (string $legacy): void {
    $this->get($legacy)
        ->assertRedirect('/developers')
        ->assertStatus(301);
})->with([
    '/documentation/some-removed-page',
    '/docs/some-removed-page',
]);

it('redirects the bare legacy documentation urls to the developers index', function (string $legacy): void {
    $this->get($legacy)
        ->assertRedirect('/developers')
        ->assertStatus(301);
})->with([
    '/documentation',
    '/docs',
]);

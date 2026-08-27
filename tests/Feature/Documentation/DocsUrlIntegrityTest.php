<?php

declare(strict_types=1);

it('serves every current developer docs url', function (string $path): void {
    $this->get($path)->assertOk();
})->with([
    '/developers',
    '/developers/self-hosting',
    '/developers/mcp',
    '/developers/contributing',
    '/developers/api',
]);

it('permanently redirects every url google indexed before the /developers rename', function (string $indexed): void {
    // These exact URLs were live and indexed (some twice -- first as
    // /documentation/*, then as /docs/*). They must never 404 or chain.
    $response = $this->get($indexed);

    $response->assertStatus(301);

    $target = $response->headers->get('Location');

    $this->get($target)->assertOk();
})->with([
    '/docs',
    '/docs/getting-started',
    '/docs/import',
    '/docs/developer',
    '/docs/self-hosting',
    '/docs/mcp',
    '/docs/api',
]);

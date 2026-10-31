<?php

declare(strict_types=1);

use Relaticle\Cloud\Docs\Http\Controllers\DocumentationController;

mutates(DocumentationController::class);

it('serves every current developer docs url', function (string $path): void {
    $this->get($path)->assertOk();
})->with([
    '/developers',
    '/developers/self-hosting',
    '/developers/mcp',
    '/developers/rest-api',
    '/developers/contributing',
    '/developers/api',
]);

it('permanently redirects every url google indexed before the /developers rename', function (string $indexed): void {
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

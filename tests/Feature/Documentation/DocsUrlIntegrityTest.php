<?php

declare(strict_types=1);

it('keeps every indexed docs url working across the content-engine migration', function (string $path): void {
    $this->get($path)->assertOk();
})->with([
    '/docs',
    '/docs/getting-started',
    '/docs/import',
    '/docs/developer',
    '/docs/self-hosting',
    '/docs/mcp',
    '/docs/api',
]);

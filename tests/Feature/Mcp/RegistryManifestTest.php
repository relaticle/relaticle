<?php

declare(strict_types=1);

function registryManifest(): array
{
    return json_decode((string) file_get_contents(base_path('server.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps every published field inside the registry schema length limits', function (string $field, int $limit): void {
    expect(mb_strlen((string) registryManifest()[$field]))->toBeLessThanOrEqual($limit);
})->with([
    'description' => ['description', 100],
    'title' => ['title', 100],
    'name' => ['name', 200],
]);

it('leaves the version placeholder for the publish workflow to substitute', function (): void {
    expect(registryManifest()['version'])->toBe('${VERSION}');
});

it('points the registry at the production MCP endpoint over streamable http', function (): void {
    expect(registryManifest()['remotes'])->toBe([
        ['type' => 'streamable-http', 'url' => 'https://mcp.relaticle.com'],
    ]);
});

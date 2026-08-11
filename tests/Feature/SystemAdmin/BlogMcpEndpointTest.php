<?php

declare(strict_types=1);

it('registers the blog mcp endpoint', function (): void {
    expect(collect(app('router')->getRoutes())->contains(
        fn ($route): bool => $route->uri() === 'mcp/blog'
    ))->toBeTrue();
});

it('rejects unauthenticated blog mcp requests', function (): void {
    $this->postJson('/mcp/blog', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertUnauthorized();
});

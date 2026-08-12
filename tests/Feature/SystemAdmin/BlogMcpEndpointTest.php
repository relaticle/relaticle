<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

// No mutates() here: this file's production code is either vendor (Relaticle\Ink\Mcp\BlogTool,
// installed from Packagist, outside app/ and packages/) or route/config wiring with no owning
// class -- neither is a valid --mutate --class target under phpunit.xml's <source> config.

it('registers the blog mcp endpoint', function (): void {
    expect(collect(app('router')->getRoutes())->contains(
        fn (Route $route): bool => $route->uri() === 'mcp/blog'
    ))->toBeTrue();
});

it('rejects unauthenticated blog mcp requests', function (): void {
    $this->postJson('/mcp/blog', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertUnauthorized();
});

it('serves tools/list to a bearer token issued to a super administrator', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $token = $admin->createToken('e2e', ['posts:read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->postJson('/mcp/blog', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('result.tools', fn (array $tools): bool => count($tools) > 0);
});

it('denies a tool call outside the token abilities', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $token = $admin->createToken('e2e-restricted', ['posts:read'])->plainTextToken;
    $category = Category::create(['name' => 'Engineering']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept', 'application/json')
        ->postJson('/mcp/blog', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create-post-tool',
                'arguments' => [
                    'title' => 'Forbidden',
                    'content' => 'x',
                    'excerpt' => 'x',
                    'category_id' => $category->id,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Token missing required ability: posts:create');

    expect(Post::where('title', 'Forbidden')->exists())->toBeFalse();
});

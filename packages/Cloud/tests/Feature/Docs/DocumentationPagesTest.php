<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Relaticle\Cloud\Docs\Http\Controllers\DocumentationController;

mutates(DocumentationController::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 42], 200),
    ]);

    config()->set('markdown.code_highlighting.enabled', false);
});

it('displays the developers index', function (): void {
    $this->get('/developers')
        ->assertOk()
        ->assertSee('Documentation');
});

it('displays the contributing guide', function (): void {
    $this->get('/developers/contributing')
        ->assertOk()
        ->assertSee('Contributing Guide');
});

it('displays the self-hosting guide', function (): void {
    $this->get('/developers/self-hosting')
        ->assertOk()
        ->assertSee('Self-Hosting Guide');
});

it('displays the MCP guide', function (): void {
    $this->get('/developers/mcp')
        ->assertOk()
        ->assertSee('MCP Server');
});

it('shows edit on GitHub link on documentation pages', function (): void {
    $this->get('/developers/self-hosting')
        ->assertOk()
        ->assertSee('Edit this page on GitHub');
});

it('returns 404 for non-existent documentation page', function (): void {
    $this->get('/developers/non-existent-page')->assertNotFound();
});

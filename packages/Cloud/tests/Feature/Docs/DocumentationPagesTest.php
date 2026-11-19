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

it('names the API reference in the page language', function (string $path, string $title, string $description): void {
    $this->get($path)->assertOk()->assertSee($title, false)->assertSee($description, false);
})->with([
    ['/developers', 'API Reference', 'REST API documentation for managing CRM entities.'],
    ['/de/developers', 'API-Referenz', 'REST-API-Dokumentation zur Verwaltung von CRM-Einträgen.'],
    ['/es/developers', 'Referencia de la API', 'Documentación de la REST API para administrar registros del CRM.'],
    ['/fr/developers', 'Référence de l&#039;API', 'Documentation de la REST API pour gérer les enregistrements du CRM.'],
    ['/de/help', 'API-Referenz', 'REST-API-Dokumentation zur Verwaltung von CRM-Einträgen.'],
]);

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

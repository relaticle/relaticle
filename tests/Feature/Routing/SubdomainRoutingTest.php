<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
});

describe('API routing - default path mode', function () {
    it('serves API at /api/v1 prefix', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/companies')->assertOk();
    });

    it('does not serve API at root /v1 prefix', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/v1/companies')->assertNotFound();
    });
});

describe('API routing - subdomain mode', function () {
    it('serves root JSON info on API subdomain', function (): void {
        config(['app.api_domain' => 'api.example.com']);

        Route::domain('api.example.com')
            ->middleware('api')
            ->group(base_path('routes/api.php'));

        $response = $this->get('http://api.example.com/');
        $response->assertOk();

        $json = $response->json();
        expect($json)->toHaveKeys(['name', 'version', 'docs']);
        expect($json['name'])->toBe('Relaticle API');
        expect($json['version'])->toBe('v1');
    });

    it('serves API resources on subdomain at /v1 prefix', function (): void {
        config(['app.api_domain' => 'api.example.com']);

        Route::domain('api.example.com')
            ->middleware('api')
            ->group(base_path('routes/api.php'));

        Sanctum::actingAs($this->user);

        $this->getJson('http://api.example.com/v1/companies')->assertOk();
    });
});

describe('MCP routing - default path mode', function () {
    it('serves MCP at /mcp path', function (): void {
        $this->get('/mcp')->assertStatus(405);
    });
});

describe('MCP routing - subdomain mode', function () {
    it('returns JSON info on MCP subdomain root', function (): void {
        config(['app.mcp_domain' => 'mcp.example.com']);

        Route::domain('mcp.example.com')->group(function (): void {
            Route::get('/', fn () => response()->json([
                'name' => 'Relaticle MCP Server',
                'version' => '1.0.0',
                'docs' => url('/docs/mcp'),
            ]));
        });

        $response = $this->get('http://mcp.example.com/');
        $response->assertOk();

        $json = $response->json();
        expect($json)->toHaveKeys(['name', 'version', 'docs']);
        expect($json['name'])->toBe('Relaticle MCP Server');
    });
});

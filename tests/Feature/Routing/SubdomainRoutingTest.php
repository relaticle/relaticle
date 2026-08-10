<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
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

describe('Scribe config matches API route prefixes', function () {
    it('uses api/v1 prefix when no API_DOMAIN is set', function (): void {
        config(['app.api_domain' => null]);

        $prefixes = require base_path('config/scribe.php');
        $prefix = $prefixes['routes'][0]['match']['prefixes'][0];

        expect($prefix)->toBe('api/v1/*');
    });

    it('uses v1 prefix when API_DOMAIN is set', function (): void {
        config(['app.api_domain' => 'api.example.com']);

        $prefixes = require base_path('config/scribe.php');
        $prefix = $prefixes['routes'][0]['match']['prefixes'][0];

        expect($prefix)->toBe('v1/*');
    });
});

describe('MCP routing - default path mode', function () {
    it('serves MCP at /mcp path', function (): void {
        $this->get('/mcp')->assertStatus(405);
    });
});

describe('MCP routing - subdomain mode', function () {
    /**
     * Mount the real MCP server at the subdomain root exactly as routes/ai.php
     * does when MCP_DOMAIN is set, so the globally prepended
     * SubdomainRootResponse middleware is exercised against the real endpoint.
     *
     * routes/ai.php is registered ahead of routes/web.php, so in production the
     * MCP endpoint — not the domainless "/" home route — owns the root of the
     * MCP domain. Routes added mid-test instead land in the dynamic tail of a
     * compiled collection, where the home route matches first; rehydrating a
     * plain collection restores the domain-first matching production has.
     */
    beforeEach(function (): void {
        config(['app.mcp_domain' => 'mcp.example.com']);

        Route::domain('mcp.example.com')->group(function (): void {
            Mcp::web('/', RelaticleServer::class);
        });

        $routes = new RouteCollection;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes->add($route);
        }

        Route::setRoutes($routes);
    });

    it('returns JSON info when a browser visits the MCP subdomain root', function (): void {
        $response = $this->get('http://mcp.example.com/', [
            'Accept' => 'text/html,application/xhtml+xml',
            'Sec-Fetch-Mode' => 'navigate',
        ]);

        $response->assertOk();

        $json = $response->json();
        expect($json)->toHaveKeys(['name', 'version', 'docs']);
        expect($json['name'])->toBe('Relaticle MCP Server');
    });

    /**
     * RFC 9728 clients (Codex via the rmcp SDK, among others) begin discovery by
     * GETting the MCP endpoint itself and treat *any* 200 as "this URL is the
     * protected resource metadata document". Answering that probe with the info
     * payload aborts the login with "metadata missing required resource field"
     * before the /.well-known fallback is ever tried.
     */
    it('does not answer a non-browser GET probe of the MCP root with the info payload', function (): void {
        $this->get('http://mcp.example.com/')->assertStatus(405);

        $this->get('http://mcp.example.com/', ['MCP-Protocol-Version' => '2024-11-05'])
            ->assertStatus(405);

        $this->get('http://mcp.example.com/', ['Accept' => '*/*'])->assertStatus(405);
    });

    it('exposes protected resource metadata that identifies the MCP root as the resource', function (): void {
        $response = $this->get('http://mcp.example.com/.well-known/oauth-protected-resource');

        $response->assertOk();

        expect($response->json('resource'))->toBe('http://mcp.example.com');
        expect($response->json('authorization_servers'))->toBe(['http://mcp.example.com']);
    });

    it('routes JSON-RPC POST to the MCP server instead of the info payload', function (): void {
        $response = $this->postJson('http://mcp.example.com/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], ['Accept' => 'application/json, text/event-stream']);

        expect($response->json('name'))->not->toBe('Relaticle MCP Server');
        expect((string) $response->getContent())->toContain('jsonrpc');
    });

    it('routes an SSE stream GET to the MCP server instead of the info payload', function (): void {
        $this->get('http://mcp.example.com/', ['Accept' => 'text/event-stream'])
            ->assertStatus(405);
    });
});

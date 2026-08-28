<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateMcpOrigin;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

mutates(ValidateMcpOrigin::class);

function reloadAiRoutesForMcpOriginTest(string $domain): void
{
    config(['app.mcp_domain' => $domain]);

    require base_path('routes/ai.php');

    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $routes->add($route);
    }

    Route::setRoutes($routes);
}

it('allows a missing Origin header to reach authentication', function (): void {
    $this->postJson('/mcp')->assertUnauthorized();
});

it('allows the MCP endpoint exact origin to reach authentication', function (): void {
    $this->postJson('/mcp', [], [
        'Origin' => 'http://RELATICLE.TEST',
    ])->assertUnauthorized();
});

it('rejects a hostile Host even when the Origin matches it', function (): void {
    $this->postJson('http://hostile.example/mcp', [], [
        'Origin' => 'http://hostile.example',
    ])->assertForbidden();
});

it('allows an exact configured origin to reach authentication', function (): void {
    config(['app.mcp_allowed_origins' => ['https://client.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => 'https://client.example',
    ])->assertUnauthorized();
});

it('rejects an unknown origin before authentication', function (): void {
    $this->postJson('/mcp', [], [
        'Origin' => 'https://unknown.example',
    ])->assertForbidden();
});

it('rejects a null origin', function (): void {
    $this->postJson('/mcp', [], [
        'Origin' => 'null',
    ])->assertForbidden();
});

it('rejects malformed and non-origin values', function (string $origin): void {
    config(['app.mcp_allowed_origins' => ['https://client.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => $origin,
    ])->assertForbidden();
})->with([
    'relative value' => 'client.example',
    'unsupported scheme' => 'ftp://client.example',
    'invalid host' => 'https://bad host',
    'credentials' => 'https://user:password@client.example',
    'query' => 'https://client.example?source=test',
    'fragment' => 'https://client.example#fragment',
    'wildcard' => 'https://*.example',
]);

it('rejects a path-bearing origin', function (): void {
    config(['app.mcp_allowed_origins' => ['https://client.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => 'https://client.example/path',
    ])->assertForbidden();
});

it('rejects comma-separated origins', function (): void {
    config(['app.mcp_allowed_origins' => ['https://first.example', 'https://second.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => 'https://first.example, https://second.example',
    ])->assertForbidden();
});

it('rejects multiple Origin header values', function (): void {
    config(['app.mcp_allowed_origins' => ['https://first.example', 'https://second.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => ['https://first.example', 'https://second.example'],
    ])->assertForbidden();
});

it('normalizes default ports', function (): void {
    config(['app.mcp_allowed_origins' => ['https://client.example']]);

    $this->postJson('/mcp', [], [
        'Origin' => 'https://CLIENT.EXAMPLE:443',
    ])->assertUnauthorized();

    $this->postJson('/mcp', [], [
        'Origin' => 'http://RELATICLE.TEST:80',
    ])->assertUnauthorized();
});

it('preserves non-default ports', function (): void {
    config(['app.mcp_allowed_origins' => ['https://client.example:8443']]);

    $this->postJson('/mcp', [], [
        'Origin' => 'https://CLIENT.EXAMPLE:8443',
    ])->assertUnauthorized();

    $this->postJson('/mcp', [], [
        'Origin' => 'https://client.example',
    ])->assertForbidden();
});

it('uses trusted proxy information for the endpoint origin', function (): void {
    config(['app.url' => 'http://public.example']);

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
    ])->postJson('http://hostile.example/mcp', [], [
        'Origin' => 'https://public.example:8443',
        'X-Forwarded-Host' => 'hostile.example:9443',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '8443',
    ])->assertUnauthorized();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.10',
    ])->postJson('http://hostile.example/mcp', [], [
        'Origin' => 'https://hostile.example:8443',
        'X-Forwarded-Host' => 'hostile.example:9443',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '8443',
    ])->assertForbidden();
});

it('does not apply Origin validation to OAuth discovery', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource', [
        'Origin' => 'https://unknown.example',
    ])->assertOk();
});

it('validates the real path-based MCP transport route', function (): void {
    config(['app.mcp_domain' => null]);

    $this->postJson('http://relaticle.test/mcp', [], [
        'Origin' => 'http://relaticle.test',
    ])->assertUnauthorized();

    $this->postJson('http://relaticle.test/mcp', [], [
        'Origin' => 'https://unknown.example',
    ])->assertForbidden();
});

it('validates the real dedicated-domain MCP transport route', function (): void {
    reloadAiRoutesForMcpOriginTest('mcp.example.com');

    $this->postJson('http://mcp.example.com/', [], [
        'Origin' => 'http://MCP.EXAMPLE.COM:80',
    ])->assertUnauthorized();

    $this->postJson('http://mcp.example.com/', [], [
        'Origin' => 'https://unknown.example',
    ])->assertForbidden();
});

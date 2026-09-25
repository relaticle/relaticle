<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

function reloadAiRoutesForOpenAiChallengeTest(?string $mcpDomain): void
{
    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() !== 'mcp.openai-apps-challenge') {
            $routes->add($route);
        }
    }

    Route::setRoutes($routes);
    config(['app.mcp_domain' => $mcpDomain]);

    require base_path('routes/ai.php');

    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $routes->add($route);
    }

    Route::setRoutes($routes);
}

it('returns 404 when the OpenAI apps challenge token is missing', function (): void {
    config(['ai.providers.openai.apps_challenge_token' => null]);

    $this->get('http://relaticle.test/.well-known/openai-apps-challenge')
        ->assertNotFound();
});

it('returns only the exact OpenAI apps challenge token as plain text', function (): void {
    config(['ai.providers.openai.apps_challenge_token' => 'challenge-token-value']);

    $response = $this->get('http://relaticle.test/.well-known/openai-apps-challenge');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertContent('challenge-token-value');

    expect($response->getContent())->not->toEndWith("\n");
});

it('serves the challenge without authentication', function (): void {
    config(['ai.providers.openai.apps_challenge_token' => 'public-challenge']);

    $this->get('http://relaticle.test/.well-known/openai-apps-challenge')
        ->assertOk()
        ->assertContent('public-challenge');
});

it('uses the application host for a path-based MCP deployment', function (): void {
    reloadAiRoutesForOpenAiChallengeTest(null);
    config(['ai.providers.openai.apps_challenge_token' => 'path-challenge']);

    $this->get('http://relaticle.test/.well-known/openai-apps-challenge')
        ->assertOk()
        ->assertContent('path-challenge');

    $this->get('http://other.example/.well-known/openai-apps-challenge')
        ->assertNotFound();
});

it('uses only the MCP domain for a dedicated-domain deployment', function (): void {
    reloadAiRoutesForOpenAiChallengeTest('mcp.example.com');
    config(['ai.providers.openai.apps_challenge_token' => 'domain-challenge']);

    $this->get('http://mcp.example.com/.well-known/openai-apps-challenge')
        ->assertOk()
        ->assertContent('domain-challenge');

    $this->get('http://relaticle.test/.well-known/openai-apps-challenge')
        ->assertNotFound();
});

it('does not conflict with OAuth discovery or the MCP transport', function (): void {
    config(['ai.providers.openai.apps_challenge_token' => 'challenge-token-value']);

    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure(['resource', 'authorization_servers']);

    $this->postJson('/mcp')->assertUnauthorized();
});

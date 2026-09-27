<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('exposes the OAuth protected-resource discovery document', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure(['resource', 'authorization_servers']);
});

it('exposes the OAuth authorization-server discovery document', function (): void {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'response_types_supported',
            'grant_types_supported',
            'code_challenge_methods_supported',
        ])
        ->assertJsonFragment(['code_challenge_methods_supported' => ['S256']]);
});

it('accepts dynamic client registration', function (): void {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Test Directory Client',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ]);

    // RFC 7591 section 3.2.1: a successful registration responds 201 Created.
    $response->assertCreated()
        ->assertJsonStructure(['client_id', 'redirect_uris', 'grant_types']);
});

it('registers a client that redirects to a known connector host', function (string $redirectUri): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Connector',
        'redirect_uris' => [$redirectUri],
    ])->assertCreated();
})->with([
    'claude' => 'https://claude.ai/api/mcp/auth_callback',
    'chatgpt' => 'https://chatgpt.com/connector_platform_oauth_redirect',
    'mistral' => 'https://console.mistral.ai/oauth/callback',
    'localhost on any port' => 'http://localhost:33418/callback',
    'ipv4 loopback' => 'http://127.0.0.1:52100/callback',
    'cursor' => 'cursor://anysphere.cursor-mcp/oauth/callback',
    'vscode' => 'vscode://vscode.mcp/oauth/callback',
]);

it('refuses a client that redirects anywhere else', function (string $redirectUri): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Connector',
        'redirect_uris' => [$redirectUri],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'unknown host' => 'https://attacker.example/callback',
    'lookalike subdomain' => 'https://claude.ai.attacker.example/callback',
    'known host over plain http' => 'http://claude.ai/api/mcp/auth_callback',
    'loopback over https' => 'https://localhost:33418/callback',
    'unlisted custom scheme' => 'evil://callback.example/oauth',
]);

it('throttles dynamic client registration after the rate limit', function (): void {
    Cache::flush();

    $payload = [
        'client_name' => 'Throttle Probe',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    // 20 successful registrations per minute per IP.
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/oauth/register', $payload)->assertCreated();
    }

    $this->postJson('/oauth/register', $payload)->assertStatus(429);
});

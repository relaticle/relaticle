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

    $response->assertOk()
        ->assertJsonStructure(['client_id', 'redirect_uris', 'grant_types']);
});

it('throttles dynamic client registration after the rate limit', function (): void {
    Cache::flush();

    $payload = [
        'client_name' => 'Throttle Probe',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    // 20 successful registrations per minute per IP.
    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/oauth/register', $payload)->assertStatus(200);
    }

    $this->postJson('/oauth/register', $payload)->assertStatus(429);
});

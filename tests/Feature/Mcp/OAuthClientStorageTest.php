<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Passport\ClientRepository;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

mutates(ClientRepository::class);

it('persists an OAuth client owned by a ULID user', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $client = Passport::clientModel()::create([
        'name' => 'Test Directory Client',
        'redirect_uris' => json_encode(['https://example.com/callback']),
        'grant_types' => json_encode(['authorization_code', 'refresh_token']),
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    expect($client->fresh())
        ->not->toBeNull()
        ->owner_id->toBe($user->getKey())
        ->owner_type->toBe($user->getMorphClass());

    expect($user->oauthApps()->count())->toBe(1);
});

it('answers an unknown client instead of failing when the authorize endpoint gets a malformed client id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $unknown = $this->actingAs($user)->get(authorizeParams((string) Str::uuid()));
    $malformed = $this->actingAs($user)->get(authorizeParams('local-qa'));

    expect($malformed->getStatusCode())->toBe($unknown->getStatusCode());
    expect($malformed->getStatusCode())->toBe(401);
});

it('answers an unknown client instead of failing when the token endpoint gets a malformed client id', function (): void {
    $unknown = $this->postJson('/oauth/token', tokenParams((string) Str::uuid()));
    $malformed = $this->postJson('/oauth/token', tokenParams('local-qa'));

    expect($malformed->getStatusCode())->toBe($unknown->getStatusCode());
    expect($malformed->getStatusCode())->toBe(401);
    expect($malformed->json('error'))->toBe('invalid_client');
});

it('still resolves a client whose id is a valid uuid', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $client = Passport::clientModel()::create([
        'name' => 'Consent Client',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get(authorizeParams((string) $client->getKey()))
        ->assertOk();
});

function authorizeParams(string $clientId): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * @return array<string, string>
 */
function tokenParams(string $clientId): array
{
    return [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://example.com/callback',
        'code_verifier' => str_repeat('b', 64),
        'code' => 'irrelevant',
    ];
}

it('accepts and refuses redirect uris by parsed origin', function (string $uri, bool $accepted): void {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Registration Probe',
        'redirect_uris' => [$uri],
    ]);

    expect($response->getStatusCode())->toBe($accepted ? 201 : 400);

    if (! $accepted) {
        expect($response->json('error'))->toBe('invalid_redirect_uri');
    }
})->with([
    'suffix host' => ['https://claude.ai.evil.com/cb', false],
    'userinfo host swap' => ['https://claude.ai@evil.com/', false],
    'origin in path' => ['https://evil.com/https://claude.ai/', false],
    'foreign port' => ['https://claude.ai:8443/cb', false],
    'plain http' => ['http://claude.ai/cb', false],
    'userinfo credentials' => ['https://user:pass@claude.ai/cb', false],
    'backslash userinfo' => ['https://evil.com\\@claude.ai/', false],
    'loopback lookalike' => ['http://localhost.evil.com/cb', false],
    'trailing dot host' => ['https://claude.ai./cb', false],
    'uppercase scheme' => ['HTTPS://claude.ai/cb', false],
    'listed origin' => ['https://claude.ai/api/mcp/auth_callback', true],
    'uppercase host' => ['https://CLAUDE.ai/x', true],
    'explicit default port' => ['https://claude.ai:443/cb', true],
    'loopback name' => ['http://localhost:4242/cb', true],
    'loopback ipv4' => ['http://127.0.0.1:61000/cb', true],
    'loopback ipv6' => ['http://[::1]:5000/cb', true],
    'cursor scheme' => ['cursor://anything/cb', true],
    'vscode scheme' => ['vscode://x/cb', true],
]);

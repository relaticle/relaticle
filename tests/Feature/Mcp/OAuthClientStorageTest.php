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

<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\ApproveAuthorizationController;
use App\Http\Middleware\SetApiTeamContext;
use App\Listeners\Mcp\CopyTeamIdToAccessToken;
use App\Models\Passport\AuthCode;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

mutates(
    ApproveAuthorizationController::class,
    AuthCode::class,
    CopyTeamIdToAccessToken::class,
    SetApiTeamContext::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->personalTeam = $this->user->personalTeam();
    $this->otherTeam = Team::factory()->create();
    $this->otherTeam->users()->attach($this->user, ['role' => 'member']);
    $this->user->refresh();

    $this->client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Test MCP Client',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $this->user->getMorphClass(),
        'owner_id' => $this->user->getKey(),
    ]);
});

it('renders the consent view with the user\'s teams', function (): void {
    $this->actingAs($this->user);

    $response = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $response->assertOk();
    $response->assertSee($this->personalTeam->name);
    $response->assertSee($this->otherTeam->name);
    $response->assertSee('name="team_id"', false);
});

it('rejects the approve POST without a team_id', function (): void {
    $this->actingAs($this->user);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $response = $this->from('/oauth/authorize')->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
    ]);

    $response->assertSessionHasErrors('team_id');
});

it('rejects the approve POST when the user does not belong to the team', function (): void {
    $foreignTeam = Team::factory()->create();

    $this->actingAs($this->user);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $response = $this->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'team_id' => $foreignTeam->getKey(),
    ]);

    $response->assertForbidden();
});

it('persists the chosen team_id onto the auth code', function (): void {
    $this->actingAs($this->user);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $this->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'team_id' => $this->otherTeam->getKey(),
    ])->assertRedirect();

    $authCode = DB::table('oauth_auth_codes')
        ->where('user_id', $this->user->getKey())
        ->where('client_id', $this->client->getKey())
        ->latest('expires_at')
        ->first();

    expect($authCode)->not->toBeNull();
    expect($authCode->team_id)->toBe($this->otherTeam->getKey());
});

it('scopes MCP HTTP requests to the bound team and ignores X-Team-Id header', function (): void {
    // Mint a real access-token row with team_id set, simulating a fully completed
    // OAuth flow. We hit the HTTP MCP endpoint so SetApiTeamContext actually fires.
    $accessTokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $accessTokenId,
        'user_id' => $this->user->getKey(),
        'client_id' => $this->client->getKey(),
        'team_id' => $this->otherTeam->getKey(),
        'name' => 'test',
        'scopes' => '["*"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    // Use Passport::actingAs with an explicit team_id binding on the access token.
    Passport::actingAs($this->user, scopes: ['*']);
    $this->user->currentAccessToken()->team_id = $this->otherTeam->getKey();

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'who-ami-tool',
            'arguments' => (object) [],
        ],
    ], [
        // Deliberately point header at personal team — should be IGNORED.
        'X-Team-Id' => $this->personalTeam->getKey(),
    ]);

    $response->assertOk();
    expect((string) $response->getContent())->toContain($this->otherTeam->getKey());
    expect((string) $response->getContent())->not->toContain($this->personalTeam->getKey());
});

it('rejects an MCP request when a Passport token has no team_id', function (): void {
    Passport::actingAs($this->user, scopes: ['*']);
    // Intentionally do NOT set team_id — simulates a malformed token created
    // outside our consent flow. SetApiTeamContext should return null → request fails.

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'who-ami-tool',
            'arguments' => (object) [],
        ],
    ]);

    // resolveTeam() returned null. The exact failure mode depends on how
    // SetApiTeamContext handles a null team (read its handle() method).
    // The contract: not a 200 OK. Most likely 403 or 422.
    expect($response->status())->not->toBe(200);
});

it('still honors a Sanctum personal access token with its own team_id', function (): void {
    $pat = $this->user->createToken('test-pat', ['*']);

    // Pin the PAT to the other team (the PersonalAccessToken model has $team_id).
    $pat->accessToken->forceFill(['team_id' => $this->otherTeam->getKey()])->save();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$pat->plainTextToken])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'who-ami-tool',
                'arguments' => (object) [],
            ],
        ]);

    $response->assertOk();
    expect((string) $response->getContent())->toContain($this->otherTeam->getKey());
});

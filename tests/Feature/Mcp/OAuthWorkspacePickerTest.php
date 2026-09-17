<?php

declare(strict_types=1);

use App\Features\Billing;
use App\Http\Controllers\Mcp\ApproveAuthorizationController;
use App\Http\Middleware\SetApiWorkspaceContext;
use App\Listeners\Mcp\CopyWorkspaceIdToAccessToken;
use App\Models\Passport\AuthCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(
    ApproveAuthorizationController::class,
    AuthCode::class,
    CopyWorkspaceIdToAccessToken::class,
    SetApiWorkspaceContext::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->personalWorkspace = $this->user->personalWorkspace();
    $this->otherWorkspace = Workspace::factory()->create();
    $this->otherWorkspace->users()->attach($this->user, ['role' => 'member']);
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

/**
 * The authorize endpoint with a valid PKCE challenge, so each test only has to
 * name the parameters it actually cares about.
 *
 * @param  array<string, string>  $overrides
 */
function authorizeUrl(Client $client, array $overrides = []): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
        ...$overrides,
    ]);
}

it('renders the consent view with the user\'s workspaces', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client));

    $response->assertOk();
    $response->assertSee($this->personalWorkspace->name);
    $response->assertSee($this->otherWorkspace->name);
    $response->assertSee('name="workspace_id"', false);
});

it('spells out what the connector will be able to do, including deletion', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client));

    $response->assertOk();
    $response->assertSee('Read and search your records');
    $response->assertSee('Create and update them');
    $response->assertSee('Delete them');
    $response->assertSee('Companies, people, opportunities, tasks and notes.');
});

it('lists only the permissions a REST client asks for', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client, ['scope' => 'read create update']));

    $response->assertOk();
    $response->assertSee('Read and search your records');
    $response->assertSee('Create and update them');
    $response->assertDontSee('Delete them');
});

it('lists only the write permission for a create-only REST client', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client, ['scope' => 'create']));

    $response->assertOk();
    $response->assertDontSee('Read and search your records');
    $response->assertSee('Create and update them');
    $response->assertDontSee('Delete them');
});

it('lists every permission for an MCP client', function (): void {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client, ['scope' => 'mcp:use']));

    $response->assertOk();
    $response->assertSee('Read and search your records');
    $response->assertSee('Create and update them');
    $response->assertSee('Delete them');
});

it('rejects the approve POST without a workspace_id', function (): void {
    $this->actingAs($this->user);

    $this->get(authorizeUrl($this->client));

    $response = $this->from('/oauth/authorize')->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
    ]);

    $response->assertSessionHasErrors('workspace_id');
});

it('rejects the approve POST when the user does not belong to the workspace', function (): void {
    $foreignWorkspace = Workspace::factory()->create();

    $this->actingAs($this->user);

    $this->get(authorizeUrl($this->client));

    $response = $this->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'workspace_id' => $foreignWorkspace->getKey(),
    ]);

    $response->assertForbidden();
});

it('marks a billing-paused workspace as unselectable on the consent screen', function (): void {
    Feature::define(Billing::class, true);

    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client));

    $response->assertOk();
    $response->assertSee('Paused. Subscribe to connect', false);
});

it('refuses to approve a connector for a billing-paused workspace', function (): void {
    Feature::define(Billing::class, true);

    $this->actingAs($this->user);

    $this->get(authorizeUrl($this->client));

    // The picker disables this option; a tampered submit must not mint a token that
    // would answer 402 on every subsequent MCP call.
    $response = $this->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'workspace_id' => $this->otherWorkspace->getKey(),
    ]);

    $response->assertStatus(402);

    expect(AuthCode::query()->where('workspace_id', $this->otherWorkspace->getKey())->exists())->toBeFalse();
});

it('persists the chosen workspace_id onto the auth code', function (): void {
    $this->actingAs($this->user);

    $this->get(authorizeUrl($this->client));

    $this->post('/oauth/authorize', [
        'state' => 'test-state',
        'client_id' => $this->client->getKey(),
        'auth_token' => session('authToken'),
        'workspace_id' => $this->otherWorkspace->getKey(),
    ])->assertRedirect();

    $authCode = DB::table('oauth_auth_codes')
        ->where('user_id', $this->user->getKey())
        ->where('client_id', $this->client->getKey())
        ->latest('expires_at')
        ->first();

    expect($authCode)->not->toBeNull();
    expect($authCode->workspace_id)->toBe($this->otherWorkspace->getKey());
});

it('scopes MCP HTTP requests to the bound workspace and ignores X-Workspace-Id header', function (): void {
    // Mint a real access-token row with workspace_id set, simulating a fully completed
    // OAuth flow. We hit the HTTP MCP endpoint so SetApiWorkspaceContext actually fires.
    $accessTokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $accessTokenId,
        'user_id' => $this->user->getKey(),
        'client_id' => $this->client->getKey(),
        'workspace_id' => $this->otherWorkspace->getKey(),
        'name' => 'test',
        'scopes' => '["*"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    // Use Passport::actingAs with an explicit workspace_id binding on the access token.
    Passport::actingAs($this->user, scopes: ['*']);
    $this->user->currentAccessToken()->workspace_id = $this->otherWorkspace->getKey();

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'who-ami-tool',
            'arguments' => (object) [],
        ],
    ], [
        // Deliberately point header at personal workspace; it should be IGNORED.
        'X-Workspace-Id' => $this->personalWorkspace->getKey(),
    ]);

    $response->assertOk();
    expect((string) $response->getContent())->toContain($this->otherWorkspace->getKey());
    expect((string) $response->getContent())->not->toContain($this->personalWorkspace->getKey());
});

it('rejects an MCP request when a Passport token has no workspace_id', function (): void {
    Passport::actingAs($this->user, scopes: ['*']);
    // Intentionally do NOT set workspace_id. This simulates a malformed token created
    // outside our consent flow. SetApiWorkspaceContext should return null → request fails.

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'who-ami-tool',
            'arguments' => (object) [],
        ],
    ]);

    // resolveWorkspace() returned null. The exact failure mode depends on how
    // SetApiWorkspaceContext handles a null workspace (read its handle() method).
    // The contract: not a 200 OK. Most likely 403 or 422.
    expect($response->status())->not->toBe(200);
});

it('still honors a Sanctum personal access token with its own workspace_id', function (): void {
    $pat = $this->user->createToken('test-pat', ['*']);

    // Pin the PAT to the other workspace (the PersonalAccessToken model has $workspace_id).
    $pat->accessToken->forceFill(['workspace_id' => $this->otherWorkspace->getKey()])->save();

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
    expect((string) $response->getContent())->toContain($this->otherWorkspace->getKey());
});

/**
 * The 'sanctum' guard used here is scoped to the users provider (config/auth.php),
 * so a token minted for any other tokenable (e.g. a blog token issued to a
 * Relaticle\SystemAdmin\Models\SystemAdministrator) must never authenticate on the
 * app's own MCP endpoint -- it is a live credential impersonating a caller type
 * SetApiWorkspaceContext (and every policy/observer downstream) does not expect.
 */
it('rejects a personal access token minted for a tokenable outside the users provider', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);
    $token = $admin->createToken('blog-token', ['posts:read'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'who-ami-tool', 'arguments' => (object) []],
        ])
        ->assertUnauthorized();
});

/**
 * The 'sanctum' guard rejecting a non-User tokenable (above) should make this
 * unreachable in production, but SetApiWorkspaceContext must not depend on that being
 * true -- Sanctum::actingAs() bypasses the guard's own provider check the same way
 * a future guard misconfiguration could, proving the middleware's own defensive
 * check is what stands between a caller type mismatch and an uncaught TypeError.
 */
it('fails closed with 403 instead of a type error when the resolved caller is not a User', function (): void {
    $admin = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]);

    Sanctum::actingAs($admin, ['posts:read']);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'who-ami-tool', 'arguments' => (object) []],
    ])->assertForbidden();
});

/**
 * Walk the real OAuth 2.1 + PKCE dance the way Claude does: consent with a workspace
 * selected, then redeem the code at the token endpoint.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function completeOauthFlow(User $user, Client $client, Workspace $workspace): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    test()->actingAs($user);

    test()->get(authorizeUrl($client, [
        'scope' => 'mcp:use',
        'state' => 'st',
        'code_challenge' => $challenge,
    ]))->assertOk();

    $location = test()->post('/oauth/authorize', [
        'state' => 'st',
        'client_id' => $client->getKey(),
        'auth_token' => session('authToken'),
        'workspace_id' => $workspace->getKey(),
    ])->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    $response = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk();

    // Drop the consent session. A real MCP client arrives with a bearer token and
    // no cookie; leaving the session in place would let Sanctum's stateful guard
    // authenticate the call and the token would never be exercised.
    test()->flushSession();
    auth()->forgetGuards();

    return [
        'access_token' => (string) $response->json('access_token'),
        'refresh_token' => (string) $response->json('refresh_token'),
    ];
}

it('binds the consented workspace to the access token minted at the token endpoint', function (): void {
    completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    $token = DB::table('oauth_access_tokens')->where('user_id', $this->user->getKey())->sole();

    expect($token->workspace_id)->toBe($this->otherWorkspace->getKey());
});

it('keeps the consented workspace when the client refreshes its access token', function (): void {
    $tokens = completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $this->client->getKey(),
        'refresh_token' => $tokens['refresh_token'],
        'scope' => '',
    ])->assertOk();

    $refreshed = DB::table('oauth_access_tokens')
        ->where('user_id', $this->user->getKey())
        ->where('revoked', false)
        ->sole();

    expect($refreshed->workspace_id)->toBe($this->otherWorkspace->getKey());
});

it('keeps the consented workspace on refresh after the consent auth code is purged', function (): void {
    $tokens = completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    // `passport:purge` clears revoked auth codes, taking the original consent with
    // them; the binding then has to come from the token being replaced.
    DB::table('oauth_auth_codes')->delete();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $this->client->getKey(),
        'refresh_token' => $tokens['refresh_token'],
        'scope' => 'mcp:use',
    ])->assertOk();

    $refreshed = DB::table('oauth_access_tokens')
        ->where('user_id', $this->user->getKey())
        ->where('revoked', false)
        ->sole();

    expect($refreshed->workspace_id)->toBe($this->otherWorkspace->getKey());
});

it('scopes MCP calls to the consented workspace rather than the user current workspace', function (): void {
    $tokens = completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$tokens['access_token']])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'who-ami-tool', 'arguments' => (object) []],
        ]);

    $response->assertOk();

    expect((string) $response->getContent())
        ->toContain($this->otherWorkspace->getKey())
        ->not->toContain($this->personalWorkspace->getKey());
});

it('refuses an MCP call when a Passport token carries no workspace binding', function (): void {
    $tokens = completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    DB::table('oauth_access_tokens')->where('user_id', $this->user->getKey())->update(['workspace_id' => null]);

    $this->withHeaders(['Authorization' => 'Bearer '.$tokens['access_token']])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'who-ami-tool', 'arguments' => (object) []],
        ])
        ->assertForbidden();
});

it('keeps the consented workspace when the client re-authorizes and Passport skips consent', function (): void {
    completeOauthFlow($this->user, $this->client, $this->otherWorkspace);

    // Passport short-circuits the consent screen (and so our workspace picker) when the
    // user already holds an active token for the client, so the second grant never
    // records a workspace of its own.
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->actingAs($this->user);

    $location = $this->get(authorizeUrl($this->client, [
        'scope' => 'mcp:use',
        'state' => 'again',
        'code_challenge' => $challenge,
    ]))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $this->client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk();

    $tokens = DB::table('oauth_access_tokens')->where('user_id', $this->user->getKey())->get();

    expect($tokens)->toHaveCount(2);
    expect($tokens->pluck('workspace_id')->unique()->all())->toBe([$this->otherWorkspace->getKey()]);
});

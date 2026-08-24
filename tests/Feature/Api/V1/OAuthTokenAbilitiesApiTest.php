<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTokenHasAbility;
use App\Http\Middleware\SetApiTeamContext;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\TransientToken as PassportTransientToken;
use League\OAuth2\Server\Exception\OAuthServerException;

mutates(EnsureTokenHasAbility::class, SetApiTeamContext::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

/**
 * Authenticate the given user through the Passport `api` guard.
 *
 * Passport::actingAs() mints a detached AccessToken with no backing row, so its
 * team_id could never resolve and every request would die in SetApiTeamContext.
 * Inserting the row the consent flow would have written (team_id included) and
 * pointing the token at it exercises the real binding instead.
 *
 * @param  list<string>  $scopes
 */
function actAsOAuthClient(User $user, array $scopes, ?Team $team): void
{
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'REST Connector',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $tokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'team_id' => $team?->getKey(),
        'name' => 'REST Connector',
        'scopes' => json_encode($scopes),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $user->withAccessToken(new AccessToken([
        'oauth_access_token_id' => $tokenId,
        'oauth_client_id' => $client->getKey(),
        'oauth_user_id' => $user->getKey(),
        'oauth_scopes' => $scopes,
    ]));

    auth()->guard('api')->setUser($user);
    auth()->shouldUse('api');
}

describe('scope catalog', function (): void {
    it('offers exactly the four REST abilities alongside the MCP scope', function (): void {
        expect(array_keys(Passport::$scopes ?? []))
            ->toEqualCanonicalizing(['read', 'create', 'update', 'delete', Registrar::OAUTH_SCOPE]);
    });

    it('gives every REST ability a human-readable description', function (): void {
        foreach (['read', 'create', 'update', 'delete'] as $scope) {
            expect(Passport::$scopes[$scope] ?? null)->toBeString()->not->toBeEmpty();
        }
    });
});

describe('create-scoped oauth token', function (): void {
    beforeEach(function (): void {
        actAsOAuthClient($this->user, ['create'], $this->team);
    });

    it('can create a person in the team bound to the token', function (): void {
        $this->postJson('/api/v1/people', ['name' => 'Ada Lovelace'])
            ->assertCreated();

        $this->assertDatabaseHas('people', [
            'name' => 'Ada Lovelace',
            'team_id' => $this->team->getKey(),
        ]);
    });

    it('cannot list people', function (): void {
        $this->getJson('/api/v1/people')->assertForbidden();
    });

    it('cannot update a person', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $this->putJson("/api/v1/people/{$person->id}", ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('cannot delete a person', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $this->deleteJson("/api/v1/people/{$person->id}")->assertForbidden();
    });
});

describe('read-scoped oauth token', function (): void {
    beforeEach(function (): void {
        actAsOAuthClient($this->user, ['read'], $this->team);
    });

    it('can list people', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson('/api/v1/people')->assertOk();

        expect(collect($response->json('data'))->pluck('id'))->toContain($person->id);
    });

    it('cannot create a person', function (): void {
        $this->postJson('/api/v1/people', ['name' => 'Blocked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('people', ['name' => 'Blocked']);
    });
});

describe('update- and delete-scoped oauth tokens', function (): void {
    it('can update a person with the update scope', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create();

        actAsOAuthClient($this->user, ['update'], $this->team);

        $this->putJson("/api/v1/people/{$person->id}", ['name' => 'Renamed'])
            ->assertOk();
    });

    it('can delete a person with the delete scope', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create();

        actAsOAuthClient($this->user, ['delete'], $this->team);

        $this->deleteJson("/api/v1/people/{$person->id}")->assertNoContent();
    });
});

describe('scope-less oauth token', function (): void {
    beforeEach(function (): void {
        actAsOAuthClient($this->user, [], $this->team);
    });

    it('cannot read', function (): void {
        $this->getJson('/api/v1/people')->assertForbidden();
    });

    it('cannot write', function (): void {
        $this->postJson('/api/v1/people', ['name' => 'Blocked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('people', ['name' => 'Blocked']);
    });
});

describe('mcp-only oauth token', function (): void {
    it('cannot reach the REST API with only the mcp scope', function (): void {
        actAsOAuthClient($this->user, [Registrar::OAUTH_SCOPE], $this->team);

        $this->getJson('/api/v1/people')->assertForbidden();
        $this->postJson('/api/v1/people', ['name' => 'Blocked'])->assertForbidden();
    });
});

describe('unbound oauth token', function (): void {
    it('is rejected when the token carries no team', function (): void {
        actAsOAuthClient($this->user, ['read'], null);

        $this->getJson('/api/v1/people')
            ->assertForbidden()
            ->assertJson(['message' => 'No team found.']);
    });
});

describe('passport cookie session', function (): void {
    it('is refused as an API credential', function (): void {
        $this->user->withAccessToken(new PassportTransientToken);
        auth()->guard('api')->setUser($this->user);
        auth()->shouldUse('api');

        $this->getJson('/api/v1/people')
            ->assertForbidden()
            ->assertJson(['message' => 'This credential cannot access the API.']);
    });

    it('cannot write either', function (): void {
        $this->user->withAccessToken(new PassportTransientToken);
        auth()->guard('api')->setUser($this->user);
        auth()->shouldUse('api');

        $this->postJson('/api/v1/people', ['name' => 'Blocked'])
            ->assertForbidden()
            ->assertJson(['message' => 'This credential cannot access the API.']);

        $this->assertDatabaseMissing('people', ['name' => 'Blocked']);
    });
});

describe('rejected bearer tokens', function (): void {
    it('does not report an error for a bearer token the OAuth server rejects', function (): void {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/people')
            ->assertUnauthorized();

        Exceptions::assertNothingReported();
    });

    it('still reports an OAuth failure that is the server\'s own fault', function (): void {
        report(OAuthServerException::serverError('token issuance blew up'));

        Exceptions::assertReported(OAuthServerException::class);
    });
});

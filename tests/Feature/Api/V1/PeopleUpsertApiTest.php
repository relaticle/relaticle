<?php

declare(strict_types=1);

use App\Actions\CustomFields\FindEntityByFieldValue;
use App\Http\Controllers\Api\V1\PeopleUpsertController;
use App\Http\Middleware\EnsureTokenHasAbility;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Sanctum\Sanctum;

mutates(
    PeopleUpsertController::class,
    FindEntityByFieldValue::class,
    EnsureTokenHasAbility::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

function upsertCustomField(string $teamId, string $entityType, string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $teamId)
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * Write a custom field value straight to the table.
 *
 * The API path refuses duplicates on `emails` (unique_per_entity_type), so the
 * ambiguous-match fixture cannot be built through it.
 */
function writeUpsertCustomFieldValue(string $teamId, string $entityType, string $entityId, string $code, mixed $value): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $teamId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'custom_field_id' => upsertCustomField($teamId, $entityType, $code)->getKey(),
        'json_value' => json_encode($value),
    ]);
}

/**
 * Authenticate through the Passport `api` guard, the credential the hosted
 * Maxforms connector actually presents.
 *
 * Mirrors the helper in OAuthTokenAbilitiesApiTest: Passport::actingAs() mints a
 * detached token with no backing row, whose team_id could never resolve in
 * SetApiTeamContext, so the row the consent flow would have written is inserted
 * and the token pointed at it.
 *
 * @param  list<string>  $scopes
 */
function actAsUpsertOAuthClient(User $user, array $scopes, Team $team): void
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
        'team_id' => $team->getKey(),
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

it('requires authentication', function (): void {
    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])->assertUnauthorized();
});

it('creates a person and returns 201 when nothing matches', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertCreated()->assertValid();

    expect($response->json('data.attributes.name'))->toBe('Grace Hopper');

    $this->assertDatabaseHas('people', ['name' => 'Grace Hopper', 'team_id' => $this->team->id]);
});

it('updates the matched person and returns 200 when the email array contains the value', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ])->assertCreated();

    $personId = $created->json('data.id');
    $peopleBefore = People::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper (Rear Admiral)',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertOk()->assertValid();

    expect($response->json('data.id'))->toBe($personId)
        ->and($response->json('data.attributes.name'))->toBe('Grace Hopper (Rear Admiral)')
        ->and(People::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count())
        ->toBe($peopleBefore);
});

it('matches a mixed-case stored email with a lowercase submitted value', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'Grace@Navy.MIL'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['Grace@Navy.MIL']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper Updated',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'));
});

it('matches a person on a second address inside the email array', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['grace@navy.mil', 'grace@yale.edu']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@yale.edu'],
        'name' => 'Grace Hopper Updated',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'));
});

it('merges custom fields on update without wiping unmapped fields', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => [
            'emails' => ['grace@navy.mil'],
            'job_title' => 'Rear Admiral',
        ],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['linkedin' => ['linkedin.com/in/grace-hopper']],
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.custom_fields.job_title'))->toBe('Rear Admiral')
        ->and(collect($response->json('data.attributes.custom_fields.emails'))->pluck('id')->all())
        ->toBe(['grace@navy.mil']);
});

it('matches on a single-value custom field stored in its own column', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'job_title', 'value' => 'Rear Admiral'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['job_title' => 'Rear Admiral'],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'job_title', 'value' => 'rear admiral'],
        'name' => 'Grace Hopper Updated',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'));
});

it('does not match a person in another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->personalTeam();

    Sanctum::actingAs($otherUser);

    $foreign = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Foreign Grace',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ])->assertCreated();

    $foreignId = $foreign->json('data.id');

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Our Grace',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe($foreignId);

    $this->assertDatabaseHas('people', ['id' => $foreignId, 'name' => 'Foreign Grace', 'team_id' => $otherTeam->id]);
    $this->assertDatabaseHas('people', ['id' => $response->json('data.id'), 'team_id' => $this->team->id]);
});

it('picks the oldest record when more than one matches', function (): void {
    $oldest = People::factory()->recycle([$this->user, $this->team])->create(['created_at' => now()->subDays(3)]);
    $newer = People::factory()->recycle([$this->user, $this->team])->create(['created_at' => now()->subDay()]);

    writeUpsertCustomFieldValue($this->team->id, 'people', $oldest->id, 'emails', ['grace@navy.mil']);
    writeUpsertCustomFieldValue($this->team->id, 'people', $newer->id, 'emails', ['grace@navy.mil']);

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Deduplicated Grace',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($oldest->id);
});

it('rejects an unknown match field', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'not_a_field', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects a match field belonging to another entity type', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'domains', 'value' => 'navy.mil'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects an inactive match field', function (): void {
    upsertCustomField($this->team->id, 'people', 'job_title')->forceFill(['active' => false])->save();

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'job_title', 'value' => 'Rear Admiral'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('requires the match object', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', ['name' => 'Grace Hopper'])
        ->assertUnprocessable()
        ->assertInvalid(['match.field', 'match.value']);
});

describe('token abilities', function (): void {
    it('refuses a token that can create but not update, even when nothing matches', function (): void {
        $token = $this->user->createToken('create-only', ['create'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/people/upsert', [
                'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
                'name' => 'Grace Hopper',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'team_id' => $this->team->id]);
    });

    it('refuses a token that can create but not update from mutating a matched record', function (): void {
        $person = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Grace Hopper']);
        writeUpsertCustomFieldValue($this->team->id, 'people', $person->id, 'emails', ['grace@navy.mil']);

        $token = $this->user->createToken('create-only', ['create'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/people/upsert', [
                'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
                'name' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('people', ['id' => $person->id, 'name' => 'Grace Hopper']);
    });

    it('refuses a token that can update but not create', function (): void {
        $token = $this->user->createToken('update-only', ['update'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/people/upsert', [
                'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
                'name' => 'Grace Hopper',
            ])
            ->assertForbidden();
    });

    it('accepts a token holding both create and update', function (): void {
        $token = $this->user->createToken('upsert', ['create', 'update'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/people/upsert', [
                'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
                'name' => 'Grace Hopper',
                'custom_fields' => ['emails' => ['grace@navy.mil']],
            ])
            ->assertCreated();
    });

    it('refuses an oauth token scoped to create only', function (): void {
        actAsUpsertOAuthClient($this->user, ['create'], $this->team);

        $this->postJson('/api/v1/people/upsert', [
            'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
            'name' => 'Grace Hopper',
        ])->assertForbidden();

        $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'team_id' => $this->team->id]);
    });

    it('accepts an oauth token scoped to both create and update', function (): void {
        actAsUpsertOAuthClient($this->user, ['create', 'update'], $this->team);

        $this->postJson('/api/v1/people/upsert', [
            'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
            'name' => 'Grace Hopper',
            'custom_fields' => ['emails' => ['grace@navy.mil']],
        ])->assertCreated();

        $this->assertDatabaseHas('people', ['name' => 'Grace Hopper', 'team_id' => $this->team->id]);
    });
});

it('does not let the upsert route shadow the show route', function (): void {
    Sanctum::actingAs($this->user);

    $person = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Routable']);

    $this->getJson("/api/v1/people/{$person->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $person->id);
});

it('associates the person with a company on create', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'company_id' => $company->id,
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertCreated();

    expect($response->json('data.attributes.company_id'))->toBe($company->id);
});

it('rejects a company from another team', function (): void {
    $foreignCompany = Company::withoutEvents(fn () => Company::factory()->for(Team::factory())->create());

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'company_id' => $foreignCompany->id,
    ])
        ->assertUnprocessable()
        ->assertInvalid(['company_id']);
});

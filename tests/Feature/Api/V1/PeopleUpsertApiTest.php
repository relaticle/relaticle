<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Http\Controllers\Api\V1\PeopleUpsertController;
use App\Http\Middleware\EnsureTokenHasAbility;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\CustomFields\EntitiesByFieldValueQuery;
use App\Support\CustomFields\CanonicalValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Tests\Helpers\WorkspaceCustomField;

mutates(
    PeopleUpsertController::class,
    EntitiesByFieldValueQuery::class,
    EnsureTokenHasAbility::class,
    CanonicalValue::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

function upsertCustomField(string $workspaceId, string $entityType, string $code): CustomField
{
    return WorkspaceCustomField::byCode($workspaceId, $entityType, $code);
}

function markUpsertCustomFieldUnique(string $workspaceId, string $entityType, string $code): void
{
    upsertCustomField($workspaceId, $entityType, $code)->update(['settings' => new CustomFieldSettingsData(unique_per_entity_type: true)]);
}

function writeUpsertCustomFieldValue(string $workspaceId, string $entityType, string $entityId, string $code, mixed $value): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $workspaceId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'custom_field_id' => upsertCustomField($workspaceId, $entityType, $code)->getKey(),
        'json_value' => json_encode($value),
    ]);
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

    $this->assertDatabaseHas('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id, 'creation_source' => CreationSource::API->value]);
});

it('updates a matched person when a required custom field is omitted', function (): void {
    upsertCustomField($this->workspace->id, 'people', 'job_title')->update(['validation_rules' => ['required' => true]]);

    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['grace@navy.mil'], 'job_title' => 'Rear Admiral'],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Brewster Hopper',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.custom_fields.job_title'))->toBe('Rear Admiral');
});

it('updates the matched person and returns 200 when the email array contains the value', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ])->assertCreated();

    $personId = $created->json('data.id');
    $peopleBefore = People::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper (Rear Admiral)',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertOk()->assertValid();

    expect($response->json('data.id'))->toBe($personId)
        ->and($response->json('data.attributes.name'))->toBe('Grace Hopper (Rear Admiral)')
        ->and(People::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())
        ->toBe($peopleBefore);
});

it('stores the matched email on the person it creates so the same call matches next time', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])->assertCreated();

    expect(collect($created->json('data.attributes.custom_fields.emails'))->pluck('id')->all())->toBe(['grace@navy.mil']);

    $repeated = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])->assertOk();

    expect($repeated->json('data.id'))->toBe($created->json('data.id'))
        ->and(People::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->where('name', 'Grace Hopper')->count())->toBe(1);
});

it('validates the match value it stores on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'not-an-email'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['custom_fields.emails.0']);

    $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id]);
});

it('leaves a matched person\'s other emails alone when the payload omits emails', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
    writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', ['grace@navy.mil', 'grace@yale.edu']);

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@yale.edu'],
        'name' => 'Grace Brewster Hopper',
    ])->assertOk();

    expect($response->json('data.id'))->toBe($person->id)
        ->and(collect($response->json('data.attributes.custom_fields.emails'))->pluck('id')->all())->toBe(['grace@navy.mil', 'grace@yale.edu']);
});

it('stores a matched single-value field as a plain value on create', function (): void {
    markUpsertCustomFieldUnique($this->workspace->id, 'people', 'job_title');

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'job_title', 'value' => 'Rear Admiral'],
        'name' => 'Grace Hopper',
    ])->assertCreated();

    expect($response->json('data.attributes.custom_fields.job_title'))->toBe('Rear Admiral');
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

it('matches on a unique single-value custom field stored in its own column', function (): void {
    markUpsertCustomFieldUnique($this->workspace->id, 'people', 'job_title');

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

it('does not match a person in another workspace', function (): void {
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = $otherUser->personalWorkspace();

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

    $this->assertDatabaseHas('people', ['id' => $foreignId, 'name' => 'Foreign Grace', 'workspace_id' => $otherWorkspace->id]);
    $this->assertDatabaseHas('people', ['id' => $response->json('data.id'), 'workspace_id' => $this->workspace->id]);
});

it('treats like wildcards in the match value literally', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
    writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', ['grace1@navy.mil']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace_@navy.mil'],
        'name' => 'Another Grace',
    ])->assertCreated();

    $this->assertDatabaseHas('people', ['id' => $person->id, 'name' => 'Grace Hopper']);
});

it('matches an email saved as a bare string before the field held a list', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
    writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', 'Grace@Navy.mil');

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Brewster Hopper',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($person->id);
});

it('answers 503 without writing when a concurrent upsert of the same value holds the lock', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock("upsert:{$this->workspace->id}:people:emails:grace@navy.mil", 10);
    $lock->get();

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'Grace@Navy.mil'],
        'name' => 'Grace Hopper',
    ])->assertServiceUnavailable()->assertHeader('Retry-After');

    $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id]);

    $lock->release();
});

it('updates a person a concurrent upsert created after this request was validated', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
    writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', ['grace@navy.mil']);

    app()->instance(EntitiesByFieldValueQuery::class, new class
    {
        private int $calls = 0;

        public function get(mixed ...$arguments): Collection
        {
            return $this->calls++ === 0 ? new Collection : new EntitiesByFieldValueQuery()->get(...$arguments);
        }
    });

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Brewster Hopper',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($person->id);
});

it('keeps the emails of a person a concurrent upsert created after validation', function (): void {
    $createConcurrentPerson = function (): People {
        $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
        writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', ['grace@navy.mil', 'grace@yale.edu']);

        return $person;
    };

    app()->instance(EntitiesByFieldValueQuery::class, new class($createConcurrentPerson)
    {
        private int $calls = 0;

        public function __construct(private readonly Closure $createConcurrentPerson) {}

        public function get(mixed ...$arguments): Collection
        {
            if ($this->calls++ < 2) {
                return new Collection;
            }

            ($this->createConcurrentPerson)();

            return new EntitiesByFieldValueQuery()->get(...$arguments);
        }
    });

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Brewster Hopper',
    ])->assertOk();

    expect($response->json('data.attributes.name'))->toBe('Grace Brewster Hopper')
        ->and(collect($response->json('data.attributes.custom_fields.emails'))->pluck('id')->all())->toBe(['grace@navy.mil', 'grace@yale.edu']);
});

it('creates a new person when only a deleted person holds the email', function (): void {
    Sanctum::actingAs($this->user);

    $deleted = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])->assertCreated();

    People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($deleted->json('data.id'))->delete();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Returns',
    ])->assertCreated();

    expect($response->json('data.id'))->not->toBe($deleted->json('data.id'))
        ->and(collect($response->json('data.attributes.custom_fields.emails'))->pluck('id')->all())->toBe(['grace@navy.mil']);
});

it('answers 409 with the matching ids and writes nothing when more than one person holds the email', function (): void {
    $oldest = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper', 'created_at' => now()->subDays(3)]);
    $newer = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'G. Hopper', 'created_at' => now()->subDay()]);

    writeUpsertCustomFieldValue($this->workspace->id, 'people', $oldest->id, 'emails', ['grace@navy.mil']);
    writeUpsertCustomFieldValue($this->workspace->id, 'people', $newer->id, 'emails', ['grace@navy.mil']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Deduplicated Grace',
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'More than one record holds this emails value. Merge the duplicates, then retry.',
            'matches' => [$oldest->id, $newer->id],
        ]);

    $this->assertDatabaseHas('people', ['id' => $oldest->id, 'name' => 'Grace Hopper']);
    $this->assertDatabaseHas('people', ['id' => $newer->id, 'name' => 'G. Hopper']);
    $this->assertDatabaseMissing('people', ['name' => 'Deduplicated Grace']);
});

it('rejects a match field that is not marked unique and names the fields it accepts', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'job_title', 'value' => 'Rear Admiral'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field' => 'The match.field must be a custom field marked unique: emails.']);
});

it('rejects the emails field once its uniqueness is switched off', function (): void {
    upsertCustomField($this->workspace->id, 'people', 'emails')->update(['settings' => new CustomFieldSettingsData(unique_per_entity_type: false)]);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
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
    markUpsertCustomFieldUnique($this->workspace->id, 'people', 'job_title');
    upsertCustomField($this->workspace->id, 'people', 'job_title')->forceFill(['active' => false])->save();

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

        $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id]);
    });

    it('refuses a token that can create but not update from mutating a matched record', function (): void {
        $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Grace Hopper']);
        writeUpsertCustomFieldValue($this->workspace->id, 'people', $person->id, 'emails', ['grace@navy.mil']);

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
        actAsOAuthClient($this->user, ['create'], $this->workspace);

        $this->postJson('/api/v1/people/upsert', [
            'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
            'name' => 'Grace Hopper',
        ])->assertForbidden();

        $this->assertDatabaseMissing('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id]);
    });

    it('accepts an oauth token scoped to both create and update', function (): void {
        actAsOAuthClient($this->user, ['create', 'update'], $this->workspace);

        $this->postJson('/api/v1/people/upsert', [
            'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
            'name' => 'Grace Hopper',
            'custom_fields' => ['emails' => ['grace@navy.mil']],
        ])->assertCreated();

        $this->assertDatabaseHas('people', ['name' => 'Grace Hopper', 'workspace_id' => $this->workspace->id]);
    });
});

it('does not let the upsert route shadow the show route', function (): void {
    Sanctum::actingAs($this->user);

    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Routable']);

    $this->getJson("/api/v1/people/{$person->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $person->id);
});

it('associates the person with a company on create', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $response = $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'company_id' => $company->id,
        'custom_fields' => ['emails' => ['grace@navy.mil']],
    ]);

    $response->assertCreated();

    expect($response->json('data.attributes.company_id'))->toBe($company->id);
});

it('rejects a company from another workspace', function (): void {
    $foreignCompany = Company::withoutEvents(fn () => Company::factory()->for(Workspace::factory())->create());

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Grace Hopper',
        'company_id' => $foreignCompany->id,
    ])
        ->assertUnprocessable()
        ->assertInvalid(['company_id']);
});

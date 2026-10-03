<?php

declare(strict_types=1);

use App\Actions\CustomFields\FindEntitiesByFieldValue;
use App\Enums\CreationSource;
use App\Http\Controllers\Api\V1\CompaniesUpsertController;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

mutates(
    CompaniesUpsertController::class,
    FindEntitiesByFieldValue::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

/**
 * @param  array<string, mixed>  $validationRules
 */
function createCompanyCustomField(string $workspaceId, string $code, string $type, array $validationRules = [], bool $unique = false): CustomField
{
    return CustomField::forceCreate([
        'tenant_id' => $workspaceId,
        'custom_field_section_id' => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'company')
            ->firstOrFail()
            ->custom_field_section_id,
        'entity_type' => 'company',
        'code' => $code,
        'name' => ucfirst($code),
        'type' => $type,
        'sort_order' => 50,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => $validationRules,
        'settings' => new CustomFieldSettingsData(unique_per_entity_type: $unique),
    ]);
}

function writeCompanyDomains(string $workspaceId, string $companyId, mixed $domains): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $workspaceId,
        'entity_type' => 'company',
        'entity_id' => $companyId,
        'custom_field_id' => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'company')
            ->where('code', 'domains')
            ->firstOrFail()
            ->getKey(),
        'json_value' => json_encode($domains),
    ]);
}

it('requires authentication', function (): void {
    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
    ])->assertUnauthorized();
});

it('creates a company and returns 201 when no company holds that domain', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    $response->assertCreated()->assertValid();

    $this->assertDatabaseHas('companies', ['name' => 'Acme Corp', 'workspace_id' => $this->workspace->id, 'creation_source' => CreationSource::API->value]);
});

it('matches an existing company by domain case-insensitively and returns 200', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ])->assertCreated();

    $companiesBefore = Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'ACME.COM'],
        'name' => 'Acme Corporation',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.name'))->toBe('Acme Corporation')
        ->and(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())
        ->toBe($companiesBefore);
});

it('matches a stored domain when the match value carries a scheme', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => ' https://ACME.com '],
        'name' => 'Acme Corporation',
        'custom_fields' => ['domains' => ['https://acme.com']],
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

it('matches a domain the api stored with its scheme', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies', [
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['https://acme.com']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'https://acme.com'],
        'name' => 'Acme Corporation',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

it('matches one company however its domain is written', function (string $matchValue): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
    ])->assertCreated();

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => $matchValue],
        'name' => 'Acme Corporation',
    ])->assertOk()->assertJsonPath('data.id', $created->json('data.id'));

    expect(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
})->with([
    'url with www' => 'https://www.acme.com',
    'www host' => 'www.acme.com',
    'trailing slash' => 'acme.com/',
    'url with path' => 'https://acme.com/about',
]);

it('stores the matched domain on the company it creates', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
    ])->assertCreated();

    expect(collect($created->json('data.attributes.custom_fields.domains'))->pluck('id')->all())->toBe(['acme.com']);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corporation',
    ])->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
});

it('matches a unique text custom field case-insensitively', function (): void {
    createCompanyCustomField($this->workspace->id, 'registry_id', 'text', unique: true);

    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'registry_id', 'value' => 'AB-1234'],
        'name' => 'Acme Corp',
        'custom_fields' => ['registry_id' => 'AB-1234'],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'registry_id', 'value' => 'ab-1234'],
        'name' => 'Acme Corporation',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'));
});

it('treats like wildcards in a domain literally', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    writeCompanyDomains($this->workspace->id, $company->id, ['acme1.com']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme_.com'],
        'name' => 'Another Acme',
        'custom_fields' => ['domains' => ['another-acme.com']],
    ])->assertCreated();

    $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Acme Corp']);
});

it('answers 503 without writing when a concurrent upsert of the same domain holds the lock', function (string $domain): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock("upsert:{$this->workspace->id}:company:domains:acme.com", 10);
    $lock->get();

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => $domain],
        'name' => 'Acme Corp',
    ])->assertServiceUnavailable()->assertHeader('Retry-After');

    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp', 'workspace_id' => $this->workspace->id]);

    $lock->release();
})->with(['Acme.com', 'https://ACME.com', 'https://www.acme.com/about']);

it('matches an existing company on a second domain', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com', 'acme.io']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'ACME.IO'],
        'name' => 'Acme Corporation',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'));
});

it('merges custom fields on update without wiping unmapped fields', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['linkedin' => ['linkedin.com/company/acme']],
    ]);

    $response->assertOk();

    expect(collect($response->json('data.attributes.custom_fields.domains'))->pluck('id')->all())
        ->toBe(['acme.com']);
});

it('does not match a company in another workspace', function (): void {
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = $otherUser->personalWorkspace();

    Sanctum::actingAs($otherUser);

    $foreign = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ])->assertCreated();

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe($foreign->json('data.id'));

    $this->assertDatabaseHas('companies', ['id' => $foreign->json('data.id'), 'workspace_id' => $otherWorkspace->id]);
});

it('answers 409 with the matching ids and writes nothing when more than one company holds the domain', function (): void {
    $oldest = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp', 'created_at' => now()->subDays(3)]);
    $newer = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'created_at' => now()->subDay()]);

    writeCompanyDomains($this->workspace->id, $oldest->id, ['acme.com']);
    writeCompanyDomains($this->workspace->id, $newer->id, ['acme.com']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'ACME.COM'],
        'name' => 'Acme Corporation',
    ])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'More than one record holds this domains value. Merge the duplicates, then retry.',
            'matches' => [$oldest->id, $newer->id],
        ]);

    expect(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->pluck('name')->sort()->values()->all())
        ->toBe(['Acme', 'Acme Corp']);
});

it('lists at most 25 matching ids in a conflict', function (): void {
    $companies = Company::factory()->count(30)->recycle([$this->user, $this->workspace])->create();
    $companies->each(fn (Company $company) => writeCompanyDomains($this->workspace->id, $company->id, ['acme.com']));

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
    ])->assertConflict();

    expect($response->json('matches'))->toHaveCount(25);
});

it('rejects the company name as a match field', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);

    expect(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

it('rejects a non-unique match field with 422 even when several companies share its value', function (): void {
    createCompanyCustomField($this->workspace->id, 'registry_id', 'text');

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'one.com'],
        'name' => 'One',
        'custom_fields' => ['domains' => ['one.com'], 'registry_id' => 'AB-1234'],
    ])->assertCreated();

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'two.com'],
        'name' => 'Two',
        'custom_fields' => ['domains' => ['two.com'], 'registry_id' => 'AB-1234'],
    ])->assertCreated();

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'registry_id', 'value' => 'AB-1234'],
        'name' => 'Three',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects an unknown match field', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'not_a_field', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects a people custom field as a company match field', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'emails', 'value' => 'grace@navy.mil'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('refuses a token that can create but not update', function (): void {
    $token = $this->user->createToken('create-only', ['create'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/companies/upsert', [
            'match' => ['field' => 'domains', 'value' => 'acme.com'],
            'name' => 'Acme Corp',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp', 'workspace_id' => $this->workspace->id]);
});

it('accepts a token holding both create and update', function (): void {
    $token = $this->user->createToken('upsert', ['create', 'update'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/companies/upsert', [
            'match' => ['field' => 'domains', 'value' => 'acme.com'],
            'name' => 'Acme Corp',
            'custom_fields' => ['domains' => ['acme.com']],
        ])
        ->assertCreated();
});

it('rejects a unique boolean-backed match field instead of failing on the query', function (): void {
    createCompanyCustomField($this->workspace->id, 'is_partner', 'toggle', unique: true);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'is_partner', 'value' => 'banana'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);

    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp', 'workspace_id' => $this->workspace->id]);
});

it('rejects a unique numeric-backed match field', function (): void {
    createCompanyCustomField($this->workspace->id, 'headcount', 'number', unique: true);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'headcount', 'value' => 'banana'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects a unique single-choice match field rather than silently creating a duplicate', function (): void {
    createCompanyCustomField($this->workspace->id, 'tier', 'select', unique: true);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'tier', 'value' => 'Enterprise'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('updates a matched company when a required custom field is omitted', function (): void {
    createCompanyCustomField($this->workspace->id, 'industry', 'text', ['required' => true]);

    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com'], 'industry' => 'Shipping'],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'domains', 'value' => 'acme.com'],
        'name' => 'Acme Corporation',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.custom_fields.industry'))->toBe('Shipping');
});

it('does not let the upsert route shadow the show route', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $this->getJson("/api/v1/companies/{$company->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $company->id);
});

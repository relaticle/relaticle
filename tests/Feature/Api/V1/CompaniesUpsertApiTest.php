<?php

declare(strict_types=1);

use App\Actions\CustomFields\FindEntityByFieldValue;
use App\Http\Controllers\Api\V1\CompaniesUpsertController;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

mutates(
    CompaniesUpsertController::class,
    FindEntityByFieldValue::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

/**
 * @param  array<int, array<string, string>>  $validationRules
 */
function createCompanyCustomField(string $teamId, string $code, string $type, array $validationRules = []): CustomField
{
    return CustomField::forceCreate([
        'tenant_id' => $teamId,
        'custom_field_section_id' => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
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
        'settings' => new CustomFieldSettingsData,
    ]);
}

it('requires authentication', function (): void {
    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ])->assertUnauthorized();
});

it('creates a company and returns 201 when no company carries that name', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ]);

    $response->assertCreated()->assertValid();

    $this->assertDatabaseHas('companies', ['name' => 'Acme Corp', 'team_id' => $this->team->id]);
});

it('matches an existing company by name case-insensitively and returns 200', function (): void {
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ])->assertCreated();

    $companiesBefore = Company::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'acme corp'],
        'name' => 'Acme Corporation',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.name'))->toBe('Acme Corporation')
        ->and(Company::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count())
        ->toBe($companiesBefore);
});

it('matches an existing company on a multi-value custom field', function (): void {
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
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
        'custom_fields' => ['domains' => ['acme.com']],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
        'custom_fields' => ['linkedin' => ['linkedin.com/company/acme']],
    ]);

    $response->assertOk();

    expect(collect($response->json('data.attributes.custom_fields.domains'))->pluck('id')->all())
        ->toBe(['acme.com']);
});

it('does not match a company in another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->personalTeam();

    Sanctum::actingAs($otherUser);

    $foreign = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ])->assertCreated();

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
    ]);

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe($foreign->json('data.id'));

    $this->assertDatabaseHas('companies', ['id' => $foreign->json('data.id'), 'team_id' => $otherTeam->id]);
});

it('picks the oldest company when more than one carries the name', function (): void {
    $oldest = Company::factory()->recycle([$this->user, $this->team])->create([
        'name' => 'Acme Corp',
        'created_at' => now()->subDays(3),
    ]);
    Company::factory()->recycle([$this->user, $this->team])->create([
        'name' => 'acme corp',
        'created_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'ACME CORP'],
        'name' => 'Acme Corp',
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($oldest->id);
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
        'match' => ['field' => 'job_title', 'value' => 'Rear Admiral'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('refuses a token that can create but not update', function (): void {
    $token = $this->user->createToken('create-only', ['create'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/companies/upsert', [
            'match' => ['field' => 'name', 'value' => 'Acme Corp'],
            'name' => 'Acme Corp',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp', 'team_id' => $this->team->id]);
});

it('accepts a token holding both create and update', function (): void {
    $token = $this->user->createToken('upsert', ['create', 'update'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/companies/upsert', [
            'match' => ['field' => 'name', 'value' => 'Acme Corp'],
            'name' => 'Acme Corp',
        ])
        ->assertCreated();
});

it('rejects a boolean-backed match field instead of failing on the query', function (): void {
    Sanctum::actingAs($this->user);

    // `icp` is a seeded toggle field present on every team, so this is reachable
    // with no customization at all. Comparing it to a string used to reach the
    // database and raise a driver error.
    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'icp', 'value' => 'banana'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);

    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp', 'team_id' => $this->team->id]);
});

it('rejects a numeric-backed match field', function (): void {
    createCompanyCustomField($this->team->id, 'headcount', 'number');

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'headcount', 'value' => 'banana'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('rejects a single-choice match field rather than silently creating a duplicate', function (): void {
    createCompanyCustomField($this->team->id, 'tier', 'select');

    Sanctum::actingAs($this->user);

    // A select field stores option keys, never the label a form submits, so a
    // lookup would always miss and create a second record.
    $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'tier', 'value' => 'Enterprise'],
        'name' => 'Acme Corp',
    ])
        ->assertUnprocessable()
        ->assertInvalid(['match.field']);
});

it('updates a matched company when a required custom field is omitted', function (): void {
    createCompanyCustomField($this->team->id, 'industry', 'text', [['name' => 'required']]);

    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corp',
        'custom_fields' => ['industry' => 'Shipping'],
    ])->assertCreated();

    $response = $this->postJson('/api/v1/companies/upsert', [
        'match' => ['field' => 'name', 'value' => 'Acme Corp'],
        'name' => 'Acme Corporation',
        'custom_fields' => ['domains' => ['acme.com']],
    ]);

    $response->assertOk();

    expect($response->json('data.id'))->toBe($created->json('data.id'))
        ->and($response->json('data.attributes.custom_fields.industry'))->toBe('Shipping');
});

it('does not let the upsert route shadow the show route', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    $this->getJson("/api/v1/companies/{$company->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $company->id);
});

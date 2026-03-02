<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/companies')->assertUnauthorized();
});

it('can list companies', function (): void {
    Sanctum::actingAs($this->user);

    $seeded = Company::query()->where('team_id', $this->team->id)->count();
    Company::factory(3)->for($this->team)->create();

    $this->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonCount($seeded + 3, 'data')
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes']]]);
});

it('can create a company', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies', ['name' => 'Acme Corp'])
        ->assertCreated()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'companies')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Acme Corp')
                    ->where('creation_source', CreationSource::API->value)
                    ->whereType('created_at', 'string')
                    ->whereType('custom_fields', 'array')
                    ->etc()
                )
                ->etc()
            )
        );

    $this->assertDatabaseHas('companies', ['name' => 'Acme Corp', 'team_id' => $this->team->id]);
});

it('validates required fields on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/companies', [])
        ->assertUnprocessable()
        ->assertInvalid(['name']);
});

it('can show a company', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->for($this->team)->create(['name' => 'Show Test']);

    $this->getJson("/api/v1/companies/{$company->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $company->id)
                ->where('type', 'companies')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Show Test')
                    ->whereType('creation_source', 'string')
                    ->whereType('custom_fields', 'array')
                    ->missing('team_id')
                    ->missing('creator_id')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can update a company', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->for($this->team)->create();

    $this->putJson("/api/v1/companies/{$company->id}", ['name' => 'Updated Name'])
        ->assertOk()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $company->id)
                ->where('type', 'companies')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Updated Name')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can delete a company', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->for($this->team)->create();

    $this->deleteJson("/api/v1/companies/{$company->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('companies', ['id' => $company->id]);
});

it('scopes companies to current team', function (): void {
    $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownCompany = Company::factory()->for($this->team)->create();

    $response = $this->getJson('/api/v1/companies');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownCompany->id);
    expect($ids)->not->toContain($otherCompany->id);
});

describe('cross-tenant isolation', function (): void {
    it('cannot show a company from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));

        $this->getJson("/api/v1/companies/{$otherCompany->id}")
            ->assertNotFound();
    });

    it('cannot update a company from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));

        $this->putJson("/api/v1/companies/{$otherCompany->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    });

    it('cannot delete a company from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));

        $this->deleteJson("/api/v1/companies/{$otherCompany->id}")
            ->assertNotFound();
    });
});

describe('includes', function (): void {
    it('can include relations on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory()->for($this->team)->create();

        $this->getJson('/api/v1/companies?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('can include relations on show endpoint with full structure', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();

        $this->getJson("/api/v1/companies/{$company->id}?include=creator")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator.data', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'users')
                )
                ->has('included.0', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'users')
                    ->has('attributes', fn (AssertableJson $json) => $json
                        ->has('name')
                        ->has('email')
                    )
                    ->etc()
                )
                ->etc()
            );
    });

    it('can include multiple relations', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();

        $this->getJson("/api/v1/companies/{$company->id}?include=creator,people")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator')
                ->has('data.relationships.people')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();

        $response = $this->getJson("/api/v1/companies/{$company->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/companies?include=secret')
            ->assertStatus(400);
    });
});

describe('custom fields', function (): void {
    beforeEach(function (): void {
        $this->section = CustomFieldSection::create([
            'tenant_id' => $this->team->id,
            'entity_type' => 'company',
            'name' => 'General',
            'code' => 'general',
            'type' => 'section',
            'sort_order' => 1,
            'active' => true,
        ]);
    });

    it('can create a company with custom fields', function (): void {
        Sanctum::actingAs($this->user);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => 'industry',
            'name' => 'Industry',
            'type' => 'text',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);

        $this->postJson('/api/v1/companies', [
            'name' => 'Acme Corp',
            'custom_fields' => [
                'industry' => 'Technology',
            ],
        ])
            ->assertCreated()
            ->assertValid()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data', fn (AssertableJson $json) => $json
                    ->has('attributes', fn (AssertableJson $json) => $json
                        ->where('name', 'Acme Corp')
                        ->where('custom_fields.industry', 'Technology')
                        ->etc()
                    )
                    ->etc()
                )
            );
    });

    it('can update a company with custom fields', function (): void {
        Sanctum::actingAs($this->user);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => 'industry',
            'name' => 'Industry',
            'type' => 'text',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);

        $company = Company::factory()->for($this->team)->create();

        $this->putJson("/api/v1/companies/{$company->id}", [
            'name' => 'Updated Name',
            'custom_fields' => [
                'industry' => 'Finance',
            ],
        ])
            ->assertOk()
            ->assertValid()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data', fn (AssertableJson $json) => $json
                    ->has('attributes', fn (AssertableJson $json) => $json
                        ->where('name', 'Updated Name')
                        ->where('custom_fields.industry', 'Finance')
                        ->etc()
                    )
                    ->etc()
                )
            );
    });

    it('validates custom field values on create', function (): void {
        Sanctum::actingAs($this->user);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => 'annual_revenue',
            'name' => 'Annual Revenue',
            'type' => 'number',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [
                ['name' => 'numeric', 'parameters' => []],
            ],
        ]);

        $this->postJson('/api/v1/companies', [
            'name' => 'Acme Corp',
            'custom_fields' => [
                'annual_revenue' => 'not-a-number',
            ],
        ])
            ->assertUnprocessable()
            ->assertInvalid(['custom_fields.annual_revenue']);
    });

    it('ignores unknown custom field codes', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', [
            'name' => 'Acme Corp',
            'custom_fields' => [
                'nonexistent_field' => 'some value',
            ],
        ])
            ->assertCreated();
    });

    it('handles orphaned custom field values gracefully', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $customField = CustomField::create([
            'tenant_id' => $this->team->getKey(),
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => 'orphan_field',
            'name' => 'Orphan Field',
            'type' => 'text',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);

        \Relaticle\CustomFields\Services\TenantContextService::withTenant(
            $this->team->getKey(),
            fn () => $company->saveCustomFields(['orphan_field' => 'test value']),
        );

        // Simulate an orphaned value by injecting a CustomFieldValue with a null customField relation
        $company->load('customFieldValues.customField');
        $orphanedValue = new \Relaticle\CustomFields\Models\CustomFieldValue;
        $orphanedValue->setRelation('customField', null);
        $company->setRelation(
            'customFieldValues',
            $company->customFieldValues->push($orphanedValue),
        );

        $resource = new \App\Http\Resources\V1\CompanyResource($company);
        $attributes = $resource->toAttributes(request());

        expect($attributes['custom_fields'])
            ->toBeArray()
            ->toHaveKey('orphan_field', 'test value');
    });
});

describe('filtering and sorting', function (): void {
    it('can filter companies by name', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
        Company::factory()->for($this->team)->create(['name' => 'Beta Inc']);

        $response = $this->getJson('/api/v1/companies?filter[name]=Acme');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name');
        expect($names)->toContain('Acme Corp');
        expect($names)->not->toContain('Beta Inc');
    });

    it('can sort companies by name ascending', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory()->for($this->team)->create(['name' => 'Zulu Corp']);
        Company::factory()->for($this->team)->create(['name' => 'Alpha Inc']);

        $response = $this->getJson('/api/v1/companies?sort=name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $alphaIndex = $names->search('Alpha Inc');
        $zuluIndex = $names->search('Zulu Corp');
        expect($alphaIndex)->toBeLessThan($zuluIndex);
    });

    it('can sort companies by name descending', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory()->for($this->team)->create(['name' => 'Alpha Inc']);
        Company::factory()->for($this->team)->create(['name' => 'Zulu Corp']);

        $response = $this->getJson('/api/v1/companies?sort=-name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $zuluIndex = $names->search('Zulu Corp');
        $alphaIndex = $names->search('Alpha Inc');
        expect($zuluIndex)->toBeLessThan($alphaIndex);
    });

    it('rejects disallowed filter fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/companies?filter[team_id]=fake')
            ->assertStatus(400);
    });

    it('rejects disallowed sort fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/companies?sort=team_id')
            ->assertStatus(400);
    });
});

describe('soft deletes', function (): void {
    it('excludes soft-deleted companies from list', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create(['name' => 'Deleted Corp']);
        $company->delete();

        $active = Company::factory()->for($this->team)->create(['name' => 'Active Corp']);

        $response = $this->getJson('/api/v1/companies');

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($active->id);
        expect($ids)->not->toContain($company->id);
    });

    it('cannot show a soft-deleted company', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();
        $company->delete();

        $this->getJson("/api/v1/companies/{$company->id}")
            ->assertNotFound();
    });

    it('cannot update a soft-deleted company', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();
        $company->delete();

        $this->putJson("/api/v1/companies/{$company->id}", ['name' => 'Revived'])
            ->assertNotFound();
    });
});

describe('pagination', function (): void {
    it('paginates with per_page parameter', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory(5)->for($this->team)->create();

        $this->getJson('/api/v1/companies?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns second page of results', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory(5)->for($this->team)->create();

        $page1 = $this->getJson('/api/v1/companies?per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/companies?per_page=3&page=2');

        $page1->assertOk()->assertJsonCount(3, 'data');
        $page2->assertOk();

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toBeEmpty();
    });

    it('caps per_page at maximum allowed value', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory(5)->for($this->team)->create();

        $response = $this->getJson('/api/v1/companies?per_page=500');
        $response->assertOk();

        expect($response->json('data'))->toBeArray();
    });

    it('returns empty data array for page beyond results', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory(2)->for($this->team)->create();

        $this->getJson('/api/v1/companies?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('mass assignment protection', function (): void {
    it('ignores team_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();

        $this->postJson('/api/v1/companies', [
            'name' => 'Test Corp',
            'team_id' => $otherTeam->id,
        ])
            ->assertCreated();

        $company = Company::query()->where('name', 'Test Corp')->first();
        expect($company->team_id)->toBe($this->team->id);
    });

    it('ignores creator_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/companies', [
            'name' => 'Test Corp',
            'creator_id' => $otherUser->id,
        ])
            ->assertCreated();

        $company = Company::query()->where('name', 'Test Corp')->first();
        expect($company->creator_id)->toBe($this->user->id);
    });

    it('ignores team_id in update request', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->for($this->team)->create();
        $otherTeam = Team::factory()->create();

        $this->putJson("/api/v1/companies/{$company->id}", [
            'name' => 'Updated',
            'team_id' => $otherTeam->id,
        ])
            ->assertOk();

        expect($company->refresh()->team_id)->toBe($this->team->id);
    });
});

describe('input validation', function (): void {
    it('rejects name exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects non-string name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', ['name' => 12345])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects array as name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', ['name' => ['nested' => 'value']])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('accepts name at exactly 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/companies', ['name' => str_repeat('a', 255)])
            ->assertCreated();
    });
});

<?php

declare(strict_types=1);

use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\DeleteOpportunity;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Enums\CreationSource;
use App\Http\Controllers\Api\V1\OpportunitiesController;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

mutates(
    OpportunitiesController::class,
    CreateOpportunity::class,
    UpdateOpportunity::class,
    DeleteOpportunity::class,
    ListOpportunities::class,
);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/opportunities')->assertUnauthorized();
});

it('can list opportunities', function (): void {
    Sanctum::actingAs($this->user);

    $seeded = Opportunity::query()->where('team_id', $this->team->id)->count();
    Opportunity::factory(3)->recycle([$this->user, $this->team])->create();

    $this->getJson('/api/v1/opportunities')
        ->assertOk()
        ->assertJsonCount($seeded + 3, 'data')
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes']]]);
});

it('can create an opportunity', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/opportunities', ['name' => 'Big Deal'])
        ->assertCreated()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'opportunities')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Big Deal')
                    ->where('creation_source', CreationSource::API->value)
                    ->whereType('created_at', 'string')
                    ->whereType('custom_fields', 'array')
                    ->missing('team_id')
                    ->missing('creator_id')
                    ->etc()
                )
                ->etc()
            )
        );

    $this->assertDatabaseHas('opportunities', ['name' => 'Big Deal', 'team_id' => $this->team->id]);
});

it('validates required fields on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/opportunities', [])
        ->assertUnprocessable()
        ->assertInvalid(['name']);
});

it('can show an opportunity', function (): void {
    Sanctum::actingAs($this->user);

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Show Test']);

    $this->getJson("/api/v1/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $opportunity->id)
                ->where('type', 'opportunities')
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

it('can update an opportunity', function (): void {
    Sanctum::actingAs($this->user);

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $this->putJson("/api/v1/opportunities/{$opportunity->id}", ['name' => 'Updated Name'])
        ->assertOk()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $opportunity->id)
                ->where('type', 'opportunities')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Updated Name')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can delete an opportunity', function (): void {
    Sanctum::actingAs($this->user);

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $this->deleteJson("/api/v1/opportunities/{$opportunity->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('opportunities', ['id' => $opportunity->id]);
});

it('scopes opportunities to current team', function (): void {
    $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownOpportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $response = $this->getJson('/api/v1/opportunities');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownOpportunity->id);
    expect($ids)->not->toContain($otherOpportunity->id);
});

describe('cross-tenant isolation', function (): void {
    it('cannot show an opportunity from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create(['team_id' => $otherTeam->id]));

        $this->getJson("/api/v1/opportunities/{$otherOpportunity->id}")
            ->assertNotFound();
    });

    it('cannot update an opportunity from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create(['team_id' => $otherTeam->id]));

        $this->putJson("/api/v1/opportunities/{$otherOpportunity->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    });

    it('cannot delete an opportunity from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create(['team_id' => $otherTeam->id]));

        $this->deleteJson("/api/v1/opportunities/{$otherOpportunity->id}")
            ->assertNotFound();
    });

    it('rejects company_id from another team on create', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => $otherTeam->id,
        ]));

        $this->postJson('/api/v1/opportunities', [
            'name' => 'Test Deal',
            'company_id' => $otherCompany->id,
        ])
            ->assertUnprocessable()
            ->assertInvalid(['company_id']);
    });

    it('rejects contact_id from another team on create', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherPerson = People::withoutEvents(fn () => People::factory()->create([
            'team_id' => $otherTeam->id,
        ]));

        $this->postJson('/api/v1/opportunities', [
            'name' => 'Test Deal',
            'contact_id' => $otherPerson->id,
        ])
            ->assertUnprocessable()
            ->assertInvalid(['contact_id']);
    });
});

describe('includes', function (): void {
    it('can include creator on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $this->getJson("/api/v1/opportunities/{$opportunity->id}?include=creator")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator.data', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'users')
                )
                ->has('included.0', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'users')
                    ->has('attributes')
                    ->etc()
                )
                ->etc()
            );
    });

    it('can include creator on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/opportunities?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('can include company on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);

        $this->getJson("/api/v1/opportunities/{$opportunity->id}?include=company")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.company.data', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'companies')
                )
                ->has('included.0', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'companies')
                    ->has('attributes')
                    ->etc()
                )
                ->etc()
            );
    });

    it('can include contact on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();
        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create(['contact_id' => $person->id]);

        $this->getJson("/api/v1/opportunities/{$opportunity->id}?include=contact")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.contact.data', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'people')
                )
                ->has('included.0', fn (AssertableJson $json) => $json
                    ->whereType('id', 'string')
                    ->where('type', 'people')
                    ->has('attributes')
                    ->etc()
                )
                ->etc()
            );
    });

    it('can include multiple relations', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);

        $this->getJson("/api/v1/opportunities/{$opportunity->id}?include=creator,company")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator')
                ->has('data.relationships.company')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson("/api/v1/opportunities/{$opportunity->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('can include relationship counts', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson('/api/v1/opportunities?include=tasksCount');

        $response->assertOk();

        $oppData = collect($response->json('data'))
            ->firstWhere('id', $opportunity->id);
        expect($oppData['attributes']['tasks_count'])->toBe(0);
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?include=secret')
            ->assertStatus(400);
    });
});

describe('filtering and sorting', function (): void {
    it('can filter opportunities by name', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Enterprise Deal']);
        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Small Contract']);

        $response = $this->getJson('/api/v1/opportunities?filter[name]=Enterprise');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name');
        expect($names)->toContain('Enterprise Deal');
        expect($names)->not->toContain('Small Contract');
    });

    it('can filter opportunities by company_id', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $matched = Opportunity::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);
        $unmatched = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson("/api/v1/opportunities?filter[company_id]={$company->id}");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($matched->id);
        expect($ids)->not->toContain($unmatched->id);
    });

    it('can sort opportunities by name ascending', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zulu Deal']);
        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Alpha Deal']);

        $response = $this->getJson('/api/v1/opportunities?sort=name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $alphaIndex = $names->search('Alpha Deal');
        $zuluIndex = $names->search('Zulu Deal');
        expect($alphaIndex)->toBeLessThan($zuluIndex);
    });

    it('can sort opportunities by name descending', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Alpha Deal']);
        Opportunity::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zulu Deal']);

        $response = $this->getJson('/api/v1/opportunities?sort=-name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $zuluIndex = $names->search('Zulu Deal');
        $alphaIndex = $names->search('Alpha Deal');
        expect($zuluIndex)->toBeLessThan($alphaIndex);
    });

    it('rejects disallowed filter fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?filter[team_id]=fake')
            ->assertStatus(400);
    });

    it('rejects disallowed sort fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?sort=team_id')
            ->assertStatus(400);
    });
});

describe('pagination', function (): void {
    it('paginates with per_page parameter', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory(5)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/opportunities?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns second page of results', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory(5)->recycle([$this->user, $this->team])->create();

        $page1 = $this->getJson('/api/v1/opportunities?per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/opportunities?per_page=3&page=2');

        $page1->assertOk()->assertJsonCount(3, 'data');
        $page2->assertOk();

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toBeEmpty();
    });

    it('caps per_page at maximum allowed value', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?per_page=500')
            ->assertUnprocessable()
            ->assertInvalid(['per_page']);
    });

    it('returns empty data array for page beyond results', function (): void {
        Sanctum::actingAs($this->user);

        Opportunity::factory(2)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/opportunities?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('mass assignment protection', function (): void {
    it('ignores team_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();

        $this->postJson('/api/v1/opportunities', [
            'name' => 'Test Deal',
            'team_id' => $otherTeam->id,
        ])
            ->assertCreated();

        $opportunity = Opportunity::query()->where('name', 'Test Deal')->first();
        expect($opportunity->team_id)->toBe($this->team->id);
    });

    it('ignores creator_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/opportunities', [
            'name' => 'Test Deal',
            'creator_id' => $otherUser->id,
        ])
            ->assertCreated();

        $opportunity = Opportunity::query()->where('name', 'Test Deal')->first();
        expect($opportunity->creator_id)->toBe($this->user->id);
    });

    it('ignores team_id in update request', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
        $otherTeam = Team::factory()->create();

        $this->putJson("/api/v1/opportunities/{$opportunity->id}", [
            'name' => 'Updated',
            'team_id' => $otherTeam->id,
        ])
            ->assertOk();

        expect($opportunity->refresh()->team_id)->toBe($this->team->id);
    });
});

describe('input validation', function (): void {
    it('rejects name exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/opportunities', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects non-string name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/opportunities', ['name' => 12345])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects array as name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/opportunities', ['name' => ['nested' => 'value']])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('accepts name at exactly 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/opportunities', ['name' => str_repeat('a', 255)])
            ->assertCreated();
    });

    it('accepts null to clear a date custom field', function (): void {
        Sanctum::actingAs($this->user);

        $section = CustomFieldSection::create([
            'tenant_id' => $this->team->id,
            'entity_type' => 'opportunity',
            'name' => 'Date Fields',
            'code' => 'date_fields_test',
            'type' => 'section',
            'sort_order' => 99,
            'active' => true,
        ]);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $section->id,
            'entity_type' => 'opportunity',
            'code' => 'target_close_date',
            'name' => 'Target Close Date',
            'type' => 'date',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $this->putJson("/api/v1/opportunities/{$opportunity->id}", [
            'name' => $opportunity->name,
            'custom_fields' => ['target_close_date' => null],
        ])
            ->assertOk()
            ->assertValid();
    });

    it('accepts null to clear a datetime custom field', function (): void {
        Sanctum::actingAs($this->user);

        $section = CustomFieldSection::create([
            'tenant_id' => $this->team->id,
            'entity_type' => 'opportunity',
            'name' => 'Timestamps',
            'code' => 'timestamps',
            'type' => 'section',
            'sort_order' => 2,
            'active' => true,
        ]);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $section->id,
            'entity_type' => 'opportunity',
            'code' => 'last_contacted_at',
            'name' => 'Last Contacted At',
            'type' => 'date-time',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

        $this->putJson("/api/v1/opportunities/{$opportunity->id}", [
            'name' => $opportunity->name,
            'custom_fields' => ['last_contacted_at' => null],
        ])
            ->assertOk()
            ->assertValid();
    });
});

describe('soft deletes', function (): void {
    it('excludes soft-deleted opportunities from list', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
        $deleted = Opportunity::factory()->recycle([$this->user, $this->team])->create();
        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/opportunities')->json('data'))->pluck('id');
        expect($ids)->toContain($opportunity->id);
        expect($ids)->not->toContain($deleted->id);
    });

    it('cannot show a soft-deleted opportunity', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
        $opportunity->delete();

        $this->getJson("/api/v1/opportunities/{$opportunity->id}")
            ->assertNotFound();
    });
});

describe('non-existent record', function (): void {
    it('returns 404 for non-existent opportunity', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities/'.Str::ulid())
            ->assertNotFound();
    });
});

it('includes company_id and contact_id in attributes', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $person = People::factory()->recycle([$this->user, $this->team])->create();
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create([
        'company_id' => $company->id,
        'contact_id' => $person->id,
    ]);

    $this->getJson("/api/v1/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data.attributes', fn (AssertableJson $json) => $json
                ->where('company_id', $company->id)
                ->where('contact_id', $person->id)
                ->etc()
            )
            ->etc()
        );
});

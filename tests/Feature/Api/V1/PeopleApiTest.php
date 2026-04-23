<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Actions\People\DeletePeople;
use App\Actions\People\ListPeople;
use App\Actions\People\UpdatePeople;
use App\Enums\CreationSource;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

mutates(
    PeopleController::class,
    CreatePeople::class,
    UpdatePeople::class,
    DeletePeople::class,
    ListPeople::class,
);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/people')->assertUnauthorized();
});

it('can list people', function (): void {
    Sanctum::actingAs($this->user);

    $people = People::factory(3)->recycle([$this->user, $this->team])->create();

    $response = $this->getJson('/api/v1/people');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes']]]);

    $ids = collect($response->json('data'))->pluck('id');
    $people->each(fn (People $person) => expect($ids)->toContain($person->id));
});

it('can create a person', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', ['name' => 'John Doe'])
        ->assertCreated()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'people')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'John Doe')
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

    $this->assertDatabaseHas('people', ['name' => 'John Doe', 'team_id' => $this->team->id]);
});

it('validates required fields on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/people', [])
        ->assertUnprocessable()
        ->assertInvalid(['name']);
});

it('can show a person', function (): void {
    Sanctum::actingAs($this->user);

    $person = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Show Test']);

    $this->getJson("/api/v1/people/{$person->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $person->id)
                ->where('type', 'people')
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

it('can update a person', function (): void {
    Sanctum::actingAs($this->user);

    $person = People::factory()->recycle([$this->user, $this->team])->create();

    $this->putJson("/api/v1/people/{$person->id}", ['name' => 'Updated Name'])
        ->assertOk()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $person->id)
                ->where('type', 'people')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Updated Name')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can delete a person', function (): void {
    Sanctum::actingAs($this->user);

    $person = People::factory()->recycle([$this->user, $this->team])->create();

    $this->deleteJson("/api/v1/people/{$person->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('people', ['id' => $person->id]);
});

it('scopes people to current team', function (): void {
    $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

    Sanctum::actingAs($this->user);

    $ownPerson = People::factory()->recycle([$this->user, $this->team])->create();

    $response = $this->getJson('/api/v1/people');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownPerson->id);
    expect($ids)->not->toContain($otherPerson->id);
});

describe('cross-tenant isolation', function (): void {
    it('cannot show a person from another team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/people/{$otherPerson->id}")
            ->assertNotFound();
    });

    it('cannot update a person from another team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

        Sanctum::actingAs($this->user);

        $this->putJson("/api/v1/people/{$otherPerson->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    });

    it('cannot delete a person from another team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

        Sanctum::actingAs($this->user);

        $this->deleteJson("/api/v1/people/{$otherPerson->id}")
            ->assertNotFound();
    });

    it('rejects company_id from another team on create', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => $otherTeam->id,
        ]));

        $this->postJson('/api/v1/people', [
            'name' => 'Test Person',
            'company_id' => $otherCompany->id,
        ])
            ->assertUnprocessable()
            ->assertInvalid(['company_id']);
    });

    it('rejects company_id from another team on update', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => $otherTeam->id,
        ]));

        $this->putJson("/api/v1/people/{$person->id}", [
            'company_id' => $otherCompany->id,
        ])
            ->assertUnprocessable()
            ->assertInvalid(['company_id']);
    });
});

describe('includes', function (): void {
    it('can include creator on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $this->getJson("/api/v1/people/{$person->id}?include=creator")
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

        People::factory()->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/people?include=creator')
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
        $person = People::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);

        $this->getJson("/api/v1/people/{$person->id}?include=company")
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

    it('can include multiple relations', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $person = People::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);

        $this->getJson("/api/v1/people/{$person->id}?include=creator,company")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator')
                ->has('data.relationships.company')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson("/api/v1/people/{$person->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('can include relationship counts', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson('/api/v1/people?include=tasksCount');

        $response->assertOk();

        $personData = collect($response->json('data'))
            ->firstWhere('id', $person->id);
        expect($personData['attributes']['tasks_count'])->toBe(0);
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people?include=secret')
            ->assertStatus(400);
    });
});

describe('filtering and sorting', function (): void {
    it('can filter people by name', function (): void {
        Sanctum::actingAs($this->user);

        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Alice Johnson']);
        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Bob Smith']);

        $response = $this->getJson('/api/v1/people?filter[name]=Alice');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name');
        expect($names)->toContain('Alice Johnson');
        expect($names)->not->toContain('Bob Smith');
    });

    it('can filter people by company_id', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $matched = People::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->id]);
        $unmatched = People::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson("/api/v1/people?filter[company_id]={$company->id}");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($matched->id);
        expect($ids)->not->toContain($unmatched->id);
    });

    it('can sort people by name ascending', function (): void {
        Sanctum::actingAs($this->user);

        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zulu Person']);
        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Alpha Person']);

        $response = $this->getJson('/api/v1/people?sort=name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $alphaIndex = $names->search('Alpha Person');
        $zuluIndex = $names->search('Zulu Person');
        expect($alphaIndex)->toBeLessThan($zuluIndex);
    });

    it('can sort people by name descending', function (): void {
        Sanctum::actingAs($this->user);

        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Alpha Person']);
        People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zulu Person']);

        $response = $this->getJson('/api/v1/people?sort=-name');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name')->values();
        $zuluIndex = $names->search('Zulu Person');
        $alphaIndex = $names->search('Alpha Person');
        expect($zuluIndex)->toBeLessThan($alphaIndex);
    });

    it('rejects disallowed filter fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people?filter[team_id]=fake')
            ->assertStatus(400);
    });

    it('rejects disallowed sort fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people?sort=team_id')
            ->assertStatus(400);
    });
});

describe('pagination', function (): void {
    it('paginates with per_page parameter', function (): void {
        Sanctum::actingAs($this->user);

        People::factory(5)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/people?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns second page of results', function (): void {
        Sanctum::actingAs($this->user);

        People::factory(5)->recycle([$this->user, $this->team])->create();

        $page1 = $this->getJson('/api/v1/people?per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/people?per_page=3&page=2');

        $page1->assertOk()->assertJsonCount(3, 'data');
        $page2->assertOk();

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toBeEmpty();
    });

    it('caps per_page at maximum allowed value', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people?per_page=500')
            ->assertUnprocessable()
            ->assertInvalid(['per_page']);
    });

    it('returns empty data array for page beyond results', function (): void {
        Sanctum::actingAs($this->user);

        People::factory(2)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/people?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('mass assignment protection', function (): void {
    it('ignores team_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();

        $this->postJson('/api/v1/people', [
            'name' => 'Test Person',
            'team_id' => $otherTeam->id,
        ])
            ->assertCreated();

        $person = People::query()->where('name', 'Test Person')->first();
        expect($person->team_id)->toBe($this->team->id);
    });

    it('ignores creator_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/people', [
            'name' => 'Test Person',
            'creator_id' => $otherUser->id,
        ])
            ->assertCreated();

        $person = People::query()->where('name', 'Test Person')->first();
        expect($person->creator_id)->toBe($this->user->id);
    });

    it('ignores team_id in update request', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();
        $otherTeam = Team::factory()->create();

        $this->putJson("/api/v1/people/{$person->id}", [
            'name' => 'Updated',
            'team_id' => $otherTeam->id,
        ])
            ->assertOk();

        expect($person->refresh()->team_id)->toBe($this->team->id);
    });
});

describe('input validation', function (): void {
    it('rejects name exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects non-string name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', ['name' => 12345])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('rejects array as name', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', ['name' => ['nested' => 'value']])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
    });

    it('accepts name at exactly 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', ['name' => str_repeat('a', 255)])
            ->assertCreated();
    });

    it('rejects invalid company_id format', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', [
            'name' => 'Test Person',
            'company_id' => 'not-a-valid-id',
        ])
            ->assertUnprocessable()
            ->assertInvalid(['company_id']);
    });
});

describe('soft deletes', function (): void {
    it('excludes soft-deleted people from list', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();
        $deleted = People::factory()->recycle([$this->user, $this->team])->create();
        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/people')->json('data'))->pluck('id');
        expect($ids)->toContain($person->id);
        expect($ids)->not->toContain($deleted->id);
    });

    it('cannot show a soft-deleted person', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->recycle([$this->user, $this->team])->create();
        $person->delete();

        $this->getJson("/api/v1/people/{$person->id}")
            ->assertNotFound();
    });
});

describe('non-existent record', function (): void {
    it('returns 404 for non-existent person', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people/'.Str::ulid())
            ->assertNotFound();
    });
});

it('includes company_id in attributes', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $person = People::factory()->recycle([$this->user, $this->team])->create([
        'company_id' => $company->id,
    ]);

    $this->getJson("/api/v1/people/{$person->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data.attributes', fn (AssertableJson $json) => $json
                ->where('company_id', $company->id)
                ->etc()
            )
            ->etc()
        );
});

<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/people')->assertUnauthorized();
});

it('can list people', function (): void {
    Sanctum::actingAs($this->user);

    $people = People::factory(3)->for($this->team)->create();

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

    $person = People::factory()->for($this->team)->create(['name' => 'Show Test']);

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

    $person = People::factory()->for($this->team)->create();

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

    $person = People::factory()->for($this->team)->create();

    $this->deleteJson("/api/v1/people/{$person->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('people', ['id' => $person->id]);
});

it('scopes people to current team', function (): void {
    $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

    Sanctum::actingAs($this->user);

    $ownPerson = People::factory()->for($this->team)->create();

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

        $person = People::factory()->for($this->team)->create();
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

        $person = People::factory()->for($this->team)->create();

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

        People::factory()->for($this->team)->create();

        $this->getJson('/api/v1/people?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $person = People::factory()->for($this->team)->create();

        $response = $this->getJson("/api/v1/people/{$person->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/people?include=secret')
            ->assertStatus(400);
    });
});

describe('filtering', function (): void {
    it('can filter people by name', function (): void {
        Sanctum::actingAs($this->user);

        People::factory()->for($this->team)->create(['name' => 'Alice Johnson']);
        People::factory()->for($this->team)->create(['name' => 'Bob Smith']);

        $response = $this->getJson('/api/v1/people?filter[name]=Alice');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('attributes.name');
        expect($names)->toContain('Alice Johnson');
        expect($names)->not->toContain('Bob Smith');
    });
});

describe('input validation', function (): void {
    it('rejects name exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/people', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['name']);
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

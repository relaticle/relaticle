<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
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
    $this->getJson('/api/v1/opportunities')->assertUnauthorized();
});

it('can list opportunities', function (): void {
    Sanctum::actingAs($this->user);

    $seeded = Opportunity::query()->where('team_id', $this->team->id)->count();
    Opportunity::factory(3)->for($this->team)->create();

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

    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Show Test']);

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

    $opportunity = Opportunity::factory()->for($this->team)->create();

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

    $opportunity = Opportunity::factory()->for($this->team)->create();

    $this->deleteJson("/api/v1/opportunities/{$opportunity->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('opportunities', ['id' => $opportunity->id]);
});

it('scopes opportunities to current team', function (): void {
    $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownOpportunity = Opportunity::factory()->for($this->team)->create();

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

        $opportunity = Opportunity::factory()->for($this->team)->create();

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

        Opportunity::factory()->for($this->team)->create();

        $this->getJson('/api/v1/opportunities?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $opportunity = Opportunity::factory()->for($this->team)->create();

        $response = $this->getJson("/api/v1/opportunities/{$opportunity->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/opportunities?include=secret')
            ->assertStatus(400);
    });
});

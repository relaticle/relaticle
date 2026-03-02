<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Note;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/notes')->assertUnauthorized();
});

it('can list notes', function (): void {
    Sanctum::actingAs($this->user);

    $seeded = Note::query()->where('team_id', $this->team->id)->count();
    Note::factory(3)->for($this->team)->create();

    $this->getJson('/api/v1/notes')
        ->assertOk()
        ->assertJsonCount($seeded + 3, 'data')
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes']]]);
});

it('can create a note', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/notes', ['title' => 'Meeting notes'])
        ->assertCreated()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'notes')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('title', 'Meeting notes')
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

    $this->assertDatabaseHas('notes', ['title' => 'Meeting notes', 'team_id' => $this->team->id]);
});

it('validates required fields on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/notes', [])
        ->assertUnprocessable()
        ->assertInvalid(['title']);
});

it('can show a note', function (): void {
    Sanctum::actingAs($this->user);

    $note = Note::factory()->for($this->team)->create(['title' => 'Show Test']);

    $this->getJson("/api/v1/notes/{$note->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $note->id)
                ->where('type', 'notes')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('title', 'Show Test')
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

it('can update a note', function (): void {
    Sanctum::actingAs($this->user);

    $note = Note::factory()->for($this->team)->create();

    $this->putJson("/api/v1/notes/{$note->id}", ['title' => 'Updated Title'])
        ->assertOk()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $note->id)
                ->where('type', 'notes')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('title', 'Updated Title')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can delete a note', function (): void {
    Sanctum::actingAs($this->user);

    $note = Note::factory()->for($this->team)->create();

    $this->deleteJson("/api/v1/notes/{$note->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('notes', ['id' => $note->id]);
});

it('scopes notes to current team', function (): void {
    $otherNote = Note::withoutEvents(fn () => Note::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownNote = Note::factory()->for($this->team)->create();

    $response = $this->getJson('/api/v1/notes');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownNote->id);
    expect($ids)->not->toContain($otherNote->id);
});

describe('cross-tenant isolation', function (): void {
    it('cannot show a note from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create(['team_id' => $otherTeam->id]));

        $this->getJson("/api/v1/notes/{$otherNote->id}")
            ->assertNotFound();
    });

    it('cannot update a note from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create(['team_id' => $otherTeam->id]));

        $this->putJson("/api/v1/notes/{$otherNote->id}", ['title' => 'Hacked'])
            ->assertNotFound();
    });

    it('cannot delete a note from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherNote = Note::withoutEvents(fn () => Note::factory()->create(['team_id' => $otherTeam->id]));

        $this->deleteJson("/api/v1/notes/{$otherNote->id}")
            ->assertNotFound();
    });
});

describe('includes', function (): void {
    it('can include creator on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->for($this->team)->create();

        $this->getJson("/api/v1/notes/{$note->id}?include=creator")
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

        Note::factory()->for($this->team)->create();

        $this->getJson('/api/v1/notes?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->for($this->team)->create();

        $response = $this->getJson("/api/v1/notes/{$note->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes?include=secret')
            ->assertStatus(400);
    });
});

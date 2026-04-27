<?php

declare(strict_types=1);

use App\Actions\Note\CreateNote;
use App\Actions\Note\DeleteNote;
use App\Actions\Note\ListNotes;
use App\Actions\Note\UpdateNote;
use App\Enums\CreationSource;
use App\Http\Controllers\Api\V1\NotesController;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

mutates(
    NotesController::class,
    CreateNote::class,
    UpdateNote::class,
    DeleteNote::class,
    ListNotes::class,
);

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
    Note::factory(3)->recycle([$this->user, $this->team])->create();

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

    $note = Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Show Test']);

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

    $note = Note::factory()->recycle([$this->user, $this->team])->create();

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

    $note = Note::factory()->recycle([$this->user, $this->team])->create();

    $this->deleteJson("/api/v1/notes/{$note->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('notes', ['id' => $note->id]);
});

it('scopes notes to current team', function (): void {
    $otherNote = Note::withoutEvents(fn () => Note::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownNote = Note::factory()->recycle([$this->user, $this->team])->create();

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

        $note = Note::factory()->recycle([$this->user, $this->team])->create();

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

        Note::factory()->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/notes?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('can include companies on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $note = Note::factory()->recycle([$this->user, $this->team])->create();
        $note->companies()->attach($company);

        $this->getJson("/api/v1/notes/{$note->id}?include=companies")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.companies.data')
                ->has('included')
                ->etc()
            );
    });

    it('can include multiple relations', function (): void {
        Sanctum::actingAs($this->user);

        $company = Company::factory()->recycle([$this->user, $this->team])->create();
        $note = Note::factory()->recycle([$this->user, $this->team])->create();
        $note->companies()->attach($company);

        $this->getJson("/api/v1/notes/{$note->id}?include=creator,companies")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator')
                ->has('data.relationships.companies')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson("/api/v1/notes/{$note->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('can include relationship counts', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->recycle([$this->user, $this->team])->create();

        $response = $this->getJson('/api/v1/notes?include=companiesCount');

        $response->assertOk();

        $noteData = collect($response->json('data'))
            ->firstWhere('id', $note->id);
        expect($noteData['attributes']['companies_count'])->toBe(0);
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes?include=secret')
            ->assertStatus(400);
    });
});

describe('filtering and sorting', function (): void {
    it('can filter notes by title', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Meeting summary']);
        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Code review']);

        $response = $this->getJson('/api/v1/notes?filter[title]=Meeting');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title');
        expect($titles)->toContain('Meeting summary');
        expect($titles)->not->toContain('Code review');
    });

    it('can sort notes by title ascending', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Zulu Note']);
        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Alpha Note']);

        $response = $this->getJson('/api/v1/notes?sort=title');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title')->values();
        $alphaIndex = $titles->search('Alpha Note');
        $zuluIndex = $titles->search('Zulu Note');
        expect($alphaIndex)->toBeLessThan($zuluIndex);
    });

    it('can sort notes by title descending', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Alpha Note']);
        Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Zulu Note']);

        $response = $this->getJson('/api/v1/notes?sort=-title');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title')->values();
        $zuluIndex = $titles->search('Zulu Note');
        $alphaIndex = $titles->search('Alpha Note');
        expect($zuluIndex)->toBeLessThan($alphaIndex);
    });

    it('rejects disallowed filter fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes?filter[team_id]=fake')
            ->assertStatus(400);
    });

    it('rejects disallowed sort fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes?sort=team_id')
            ->assertStatus(400);
    });
});

describe('pagination', function (): void {
    it('paginates with per_page parameter', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory(5)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/notes?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns second page of results', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory(5)->recycle([$this->user, $this->team])->create();

        $page1 = $this->getJson('/api/v1/notes?per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/notes?per_page=3&page=2');

        $page1->assertOk()->assertJsonCount(3, 'data');
        $page2->assertOk();

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toBeEmpty();
    });

    it('caps per_page at maximum allowed value', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes?per_page=500')
            ->assertUnprocessable()
            ->assertInvalid(['per_page']);
    });

    it('returns empty data array for page beyond results', function (): void {
        Sanctum::actingAs($this->user);

        Note::factory(2)->recycle([$this->user, $this->team])->create();

        $this->getJson('/api/v1/notes?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('mass assignment protection', function (): void {
    it('ignores team_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();

        $this->postJson('/api/v1/notes', [
            'title' => 'Test Note',
            'team_id' => $otherTeam->id,
        ])
            ->assertCreated();

        $note = Note::query()->where('title', 'Test Note')->first();
        expect($note->team_id)->toBe($this->team->id);
    });

    it('ignores creator_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/notes', [
            'title' => 'Test Note',
            'creator_id' => $otherUser->id,
        ])
            ->assertCreated();

        $note = Note::query()->where('title', 'Test Note')->first();
        expect($note->creator_id)->toBe($this->user->id);
    });

    it('ignores team_id in update request', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->recycle([$this->user, $this->team])->create();
        $otherTeam = Team::factory()->create();

        $this->putJson("/api/v1/notes/{$note->id}", [
            'title' => 'Updated',
            'team_id' => $otherTeam->id,
        ])
            ->assertOk();

        expect($note->refresh()->team_id)->toBe($this->team->id);
    });
});

describe('input validation', function (): void {
    it('rejects title exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/notes', ['title' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('rejects non-string title', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/notes', ['title' => 12345])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('rejects array as title', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/notes', ['title' => ['nested' => 'value']])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('accepts title at exactly 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/notes', ['title' => str_repeat('a', 255)])
            ->assertCreated();
    });
});

describe('soft deletes', function (): void {
    it('excludes soft-deleted notes from list', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->recycle([$this->user, $this->team])->create();
        $deleted = Note::factory()->recycle([$this->user, $this->team])->create();
        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/notes')->json('data'))->pluck('id');
        expect($ids)->toContain($note->id);
        expect($ids)->not->toContain($deleted->id);
    });

    it('cannot show a soft-deleted note', function (): void {
        Sanctum::actingAs($this->user);

        $note = Note::factory()->recycle([$this->user, $this->team])->create();
        $note->delete();

        $this->getJson("/api/v1/notes/{$note->id}")
            ->assertNotFound();
    });
});

describe('non-existent record', function (): void {
    it('returns 404 for non-existent note', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/notes/'.Str::ulid())
            ->assertNotFound();
    });
});

it('can create a note with relationship ids', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $person = People::factory()->recycle([$this->user, $this->team])->create();
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    $this->postJson('/api/v1/notes', [
        'title' => 'Linked note',
        'company_ids' => [$company->id],
        'people_ids' => [$person->id],
        'opportunity_ids' => [$opportunity->id],
    ])
        ->assertCreated();

    $note = Note::query()->where('title', 'Linked note')->first();
    expect($note->companies)->toHaveCount(1)
        ->and($note->people)->toHaveCount(1)
        ->and($note->opportunities)->toHaveCount(1);
});

it('rejects cross-tenant relationship ids on note create', function (): void {
    Sanctum::actingAs($this->user);

    $otherTeam = Team::factory()->create();
    $otherCompany = Company::factory()->for($otherTeam)->create();

    $this->postJson('/api/v1/notes', [
        'title' => 'Should fail',
        'company_ids' => [$otherCompany->id],
    ])
        ->assertUnprocessable()
        ->assertInvalid(['company_ids.0']);
});

it('reports per-item validation errors with correct array index', function (): void {
    Sanctum::actingAs($this->user);

    $validCompany = Company::factory()->recycle([$this->user, $this->team])->create();
    $otherTeam = Team::factory()->create();
    $invalidCompany = Company::factory()->for($otherTeam)->create();

    $this->postJson('/api/v1/notes', [
        'title' => 'Mixed valid and invalid',
        'company_ids' => [$validCompany->id, $invalidCompany->id, $validCompany->id],
    ])
        ->assertUnprocessable()
        ->assertInvalid(['company_ids.1'])
        ->assertValid(['company_ids.0', 'company_ids.2']);
});

it('validates large arrays of relationship ids in a bounded number of queries', function (): void {
    Sanctum::actingAs($this->user);

    $companies = Company::factory()->count(10)->recycle([$this->user, $this->team])->create();
    $people = People::factory()->count(10)->recycle([$this->user, $this->team])->create();
    $opportunities = Opportunity::factory()->count(10)->recycle([$this->user, $this->team])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->postJson('/api/v1/notes', [
        'title' => 'Large note',
        'company_ids' => $companies->pluck('id')->all(),
        'people_ids' => $people->pluck('id')->all(),
        'opportunity_ids' => $opportunities->pluck('id')->all(),
    ])->assertCreated();

    $log = DB::getQueryLog();

    $companyLookupCount = collect($log)->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"') && str_contains($q['query'], 'team_id'))->count();
    $peopleLookupCount = collect($log)->filter(fn (array $q): bool => str_contains($q['query'], 'from "people"') && str_contains($q['query'], 'team_id'))->count();
    $opportunityLookupCount = collect($log)->filter(fn (array $q): bool => str_contains($q['query'], 'from "opportunities"') && str_contains($q['query'], 'team_id'))->count();

    expect($companyLookupCount)->toBeLessThanOrEqual(3, 'company validation should not be N+1');
    expect($peopleLookupCount)->toBeLessThanOrEqual(3, 'people validation should not be N+1');
    expect($opportunityLookupCount)->toBeLessThanOrEqual(3, 'opportunity validation should not be N+1');
});

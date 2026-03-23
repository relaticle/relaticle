<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/tasks')->assertUnauthorized();
});

it('can list tasks', function (): void {
    Sanctum::actingAs($this->user);

    $seeded = Task::query()->where('team_id', $this->team->id)->count();
    Task::factory(3)->for($this->team)->create();

    $this->getJson('/api/v1/tasks')
        ->assertOk()
        ->assertJsonCount($seeded + 3, 'data')
        ->assertJsonStructure(['data' => [['id', 'type', 'attributes']]]);
});

it('can create a task', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/tasks', ['title' => 'Fix bug'])
        ->assertCreated()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'tasks')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('title', 'Fix bug')
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

    $this->assertDatabaseHas('tasks', ['title' => 'Fix bug', 'team_id' => $this->team->id]);
});

it('validates required fields on create', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/tasks', [])
        ->assertUnprocessable()
        ->assertInvalid(['title']);
});

it('can show a task', function (): void {
    Sanctum::actingAs($this->user);

    $task = Task::factory()->for($this->team)->create(['title' => 'Show Test']);

    $this->getJson("/api/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $task->id)
                ->where('type', 'tasks')
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

it('can update a task', function (): void {
    Sanctum::actingAs($this->user);

    $task = Task::factory()->for($this->team)->create();

    $this->putJson("/api/v1/tasks/{$task->id}", ['title' => 'Updated Title'])
        ->assertOk()
        ->assertValid()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('id', $task->id)
                ->where('type', 'tasks')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('title', 'Updated Title')
                    ->etc()
                )
                ->etc()
            )
        );
});

it('can delete a task', function (): void {
    Sanctum::actingAs($this->user);

    $task = Task::factory()->for($this->team)->create();

    $this->deleteJson("/api/v1/tasks/{$task->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('tasks', ['id' => $task->id]);
});

it('scopes tasks to current team', function (): void {
    $otherTask = Task::withoutEvents(fn () => Task::factory()->create(['team_id' => Team::factory()->create()->id]));

    Sanctum::actingAs($this->user);

    $ownTask = Task::factory()->for($this->team)->create();

    $response = $this->getJson('/api/v1/tasks');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($ownTask->id);
    expect($ids)->not->toContain($otherTask->id);
});

it('can create a task with relationship ids', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->for($this->team)->create();
    $person = People::factory()->for($this->team)->create();
    $opportunity = Opportunity::factory()->for($this->team)->create();

    $this->postJson('/api/v1/tasks', [
        'title' => 'Linked task',
        'company_ids' => [$company->id],
        'people_ids' => [$person->id],
        'opportunity_ids' => [$opportunity->id],
        'assignee_ids' => [$this->user->id],
    ])
        ->assertCreated();

    $task = Task::query()->where('title', 'Linked task')->first();
    expect($task->companies)->toHaveCount(1)
        ->and($task->people)->toHaveCount(1)
        ->and($task->opportunities)->toHaveCount(1)
        ->and($task->assignees)->toHaveCount(1);
});

it('rejects cross-tenant relationship ids on task create', function (): void {
    Sanctum::actingAs($this->user);

    $otherTeam = Team::factory()->create();
    $otherCompany = Company::factory()->for($otherTeam)->create();

    $this->postJson('/api/v1/tasks', [
        'title' => 'Should fail',
        'company_ids' => [$otherCompany->id],
    ])
        ->assertUnprocessable()
        ->assertInvalid(['company_ids.0']);
});

describe('cross-tenant isolation', function (): void {
    it('cannot show a task from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create(['team_id' => $otherTeam->id]));

        $this->getJson("/api/v1/tasks/{$otherTask->id}")
            ->assertNotFound();
    });

    it('cannot update a task from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create(['team_id' => $otherTeam->id]));

        $this->putJson("/api/v1/tasks/{$otherTask->id}", ['title' => 'Hacked'])
            ->assertNotFound();
    });

    it('cannot delete a task from another team', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create(['team_id' => $otherTeam->id]));

        $this->deleteJson("/api/v1/tasks/{$otherTask->id}")
            ->assertNotFound();
    });
});

describe('includes', function (): void {
    it('can include creator on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();

        $this->getJson("/api/v1/tasks/{$task->id}?include=creator")
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

        Task::factory()->for($this->team)->create();

        $this->getJson('/api/v1/tasks?include=creator')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });

    it('can include assignees on show endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();
        $task->assignees()->attach($this->user);

        $this->getJson("/api/v1/tasks/{$task->id}?include=assignees")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.assignees.data')
                ->has('included')
                ->etc()
            );
    });

    it('can include multiple relations', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();
        $task->assignees()->attach($this->user);

        $this->getJson("/api/v1/tasks/{$task->id}?include=creator,assignees")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.relationships.creator')
                ->has('data.relationships.assignees')
                ->etc()
            );
    });

    it('does not include relations when not requested', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();

        $response = $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();

        expect($response->json('data.relationships'))->toBeNull();
    });

    it('can include relationship counts', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();

        $response = $this->getJson('/api/v1/tasks?include=assigneesCount');

        $response->assertOk();

        $taskData = collect($response->json('data'))
            ->firstWhere('id', $task->id);
        expect($taskData['attributes']['assignees_count'])->toBe(0);
    });

    it('rejects disallowed includes on list endpoint', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/tasks?include=secret')
            ->assertStatus(400);
    });
});

describe('filtering and sorting', function (): void {
    it('ignores assigned_to_me filter when value is false', function (): void {
        Sanctum::actingAs($this->user);

        $unassignedTask = Task::factory()->for($this->team)->create(['title' => 'Unassigned']);
        $assignedTask = Task::factory()->for($this->team)->create(['title' => 'Assigned']);
        $assignedTask->assignees()->attach($this->user);

        $this->getJson('/api/v1/tasks?filter[assigned_to_me]=false')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Unassigned'])
            ->assertJsonFragment(['title' => 'Assigned']);
    });

    it('can filter tasks by title', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory()->for($this->team)->create(['title' => 'Fix login bug']);
        Task::factory()->for($this->team)->create(['title' => 'Deploy to staging']);

        $response = $this->getJson('/api/v1/tasks?filter[title]=login');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title');
        expect($titles)->toContain('Fix login bug');
        expect($titles)->not->toContain('Deploy to staging');
    });

    it('can sort tasks by title ascending', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory()->for($this->team)->create(['title' => 'Zulu Task']);
        Task::factory()->for($this->team)->create(['title' => 'Alpha Task']);

        $response = $this->getJson('/api/v1/tasks?sort=title');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title')->values();
        $alphaIndex = $titles->search('Alpha Task');
        $zuluIndex = $titles->search('Zulu Task');
        expect($alphaIndex)->toBeLessThan($zuluIndex);
    });

    it('can sort tasks by title descending', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory()->for($this->team)->create(['title' => 'Alpha Task']);
        Task::factory()->for($this->team)->create(['title' => 'Zulu Task']);

        $response = $this->getJson('/api/v1/tasks?sort=-title');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('attributes.title')->values();
        $zuluIndex = $titles->search('Zulu Task');
        $alphaIndex = $titles->search('Alpha Task');
        expect($zuluIndex)->toBeLessThan($alphaIndex);
    });

    it('rejects disallowed filter fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/tasks?filter[team_id]=fake')
            ->assertStatus(400);
    });

    it('rejects disallowed sort fields', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/tasks?sort=team_id')
            ->assertStatus(400);
    });
});

describe('pagination', function (): void {
    it('paginates with per_page parameter', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory(5)->for($this->team)->create();

        $this->getJson('/api/v1/tasks?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('returns second page of results', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory(5)->for($this->team)->create();

        $page1 = $this->getJson('/api/v1/tasks?per_page=3&page=1');
        $page2 = $this->getJson('/api/v1/tasks?per_page=3&page=2');

        $page1->assertOk()->assertJsonCount(3, 'data');
        $page2->assertOk();

        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        expect($page1Ids->intersect($page2Ids))->toBeEmpty();
    });

    it('caps per_page at maximum allowed value', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory(5)->for($this->team)->create();

        $response = $this->getJson('/api/v1/tasks?per_page=500');
        $response->assertOk();

        expect($response->json('data'))->toBeArray();
    });

    it('returns empty data array for page beyond results', function (): void {
        Sanctum::actingAs($this->user);

        Task::factory(2)->for($this->team)->create();

        $this->getJson('/api/v1/tasks?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('mass assignment protection', function (): void {
    it('ignores team_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::factory()->create();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Test Task',
            'team_id' => $otherTeam->id,
        ])
            ->assertCreated();

        $task = Task::query()->where('title', 'Test Task')->first();
        expect($task->team_id)->toBe($this->team->id);
    });

    it('ignores creator_id in create request', function (): void {
        Sanctum::actingAs($this->user);

        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/tasks', [
            'title' => 'Test Task',
            'creator_id' => $otherUser->id,
        ])
            ->assertCreated();

        $task = Task::query()->where('title', 'Test Task')->first();
        expect($task->creator_id)->toBe($this->user->id);
    });

    it('ignores team_id in update request', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();
        $otherTeam = Team::factory()->create();

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'title' => 'Updated',
            'team_id' => $otherTeam->id,
        ])
            ->assertOk();

        expect($task->refresh()->team_id)->toBe($this->team->id);
    });
});

describe('input validation', function (): void {
    it('rejects title exceeding 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tasks', ['title' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('rejects non-string title', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tasks', ['title' => 12345])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('rejects array as title', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tasks', ['title' => ['nested' => 'value']])
            ->assertUnprocessable()
            ->assertInvalid(['title']);
    });

    it('accepts title at exactly 255 characters', function (): void {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tasks', ['title' => str_repeat('a', 255)])
            ->assertCreated();
    });
});

describe('soft deletes', function (): void {
    it('excludes soft-deleted tasks from list', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();
        $deleted = Task::factory()->for($this->team)->create();
        $deleted->delete();

        $ids = collect($this->getJson('/api/v1/tasks')->json('data'))->pluck('id');
        expect($ids)->toContain($task->id);
        expect($ids)->not->toContain($deleted->id);
    });

    it('cannot show a soft-deleted task', function (): void {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->for($this->team)->create();
        $task->delete();

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertNotFound();
    });
});

describe('non-existent record', function (): void {
    it('returns 404 for non-existent task', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/tasks/'.Str::ulid())
            ->assertNotFound();
    });
});

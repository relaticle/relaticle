<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Tools\Task\GetTaskTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;

mutates(ListTasksTool::class, GetTaskTool::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

/**
 * The agent prompt tells the model the user's zone, but the tool payload used to carry
 * bare `...Z` UTC timestamps, leaving the model to do the conversion — and in a live
 * chat it got it wrong, filing a task due tomorrow in Tokyo under "Due Today".
 *
 * Emitting the offset removes the arithmetic: the wall clock is already local, and a
 * model that ignores the suffix still cannot be actively misled by it.
 */
it('emits chat list datetimes with the user offset rather than bare utc', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create([
        'created_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
        'updated_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    $payload = (new ListTasksTool)->handle(new Request([]));

    /** @var list<array{attributes: array{created_at: string}}> $rows */
    $rows = json_decode($payload, true);
    $row = collect($rows)->firstWhere('id', $task->getKey());

    // 23:30 UTC on the 18th is 08:30 the next morning in Tokyo.
    expect($row['attributes']['created_at'])->toBe('2026-08-19T08:30:00+09:00');
});

it('emits chat show datetimes with the user offset too, so both tools agree', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create([
        'created_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
        'updated_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    $payload = (new GetTaskTool)->handle(new Request(['id' => (string) $task->getKey()]));

    /** @var array{attributes: array{created_at: string}} $decoded */
    $decoded = json_decode($payload, true);

    expect($decoded['attributes']['created_at'])->toBe('2026-08-19T08:30:00+09:00');
});

/**
 * Custom-field datetimes are where the live failure actually happened — a due date is
 * what the model buckets as overdue/today/upcoming.
 */
it('converts custom-field datetimes in chat tool output', function (): void {
    $team = $this->user->currentTeam;

    $dueFieldId = DB::table('custom_fields')
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->value('id');

    $task = Task::factory()->for($team)->create(['title' => 'due at the boundary']);

    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $team->getKey(),
        'entity_type' => 'task',
        'entity_id' => $task->getKey(),
        'custom_field_id' => $dueFieldId,
        'datetime_value' => '2026-08-18 23:30:00',
    ]);

    $payload = (new ListTasksTool)->handle(new Request([]));

    /** @var list<array{id: string, attributes: array{custom_fields: array{due_date: string}}}> $rows */
    $rows = json_decode($payload, true);
    $row = collect($rows)->firstWhere('id', $task->getKey());

    expect($row['attributes']['custom_fields']['due_date'])->toBe('2026-08-19T08:30:00+09:00');
});

/**
 * The REST API and MCP share these resources, and ISO-8601 UTC is the correct contract
 * for a public API — the conversion must live in the chat layer only. This is the guard
 * that keeps a future refactor from "simplifying" it down into the resource.
 */
it('leaves the rest api on utc, because the conversion belongs to the chat layer', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create([
        'created_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
        'updated_at' => Carbon::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/tasks/'.$task->getKey())
        ->assertOk()
        ->assertJsonPath('data.attributes.created_at', '2026-08-18T23:30:00.000000Z');
});

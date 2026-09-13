<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\DigestService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

function digestDueField(string $workspaceId): string
{
    $row = DB::table('custom_fields')->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')->where('code', 'due_date')->first();
    throw_if($row === null, RuntimeException::class, "due_date not seeded for {$workspaceId}");

    return trim((string) $row->id);
}

/** @return array{0: string, 1: string} */
function digestStatusDoneOption(string $workspaceId): array
{
    $field = DB::table('custom_fields')->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')->where('code', 'status')->first();
    $fieldId = trim((string) $field->id);
    $done = DB::table('custom_field_options')->where('custom_field_id', $fieldId)->where('name', 'Done')->first();

    return [$fieldId, trim((string) $done->id)];
}

function digestSetDue(Task $task, string $fieldId, DateTimeInterface $dueAt): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $fieldId,
        'tenant_id' => $task->workspace_id,
        'datetime_value' => $dueAt->format('Y-m-d H:i:s'),
    ]);
}

function digestSetStatus(Task $task, string $fieldId, string $optionId): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $fieldId,
        'tenant_id' => $task->workspace_id,
        'string_value' => $optionId,
    ]);
}

it('daily digest contains overdue and due-today tasks only', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $field = digestDueField($workspace->id);

    $overdue = Task::factory()->for($workspace)->create(['title' => 'overdue']);
    $overdue->assignees()->attach($user);
    digestSetDue($overdue, $field, now()->subDay());

    $today = Task::factory()->for($workspace)->create(['title' => 'today']);
    $today->assignees()->attach($user);
    digestSetDue($today, $field, now()->setTime(15, 0));

    $nextWeek = Task::factory()->for($workspace)->create(['title' => 'next_week']);
    $nextWeek->assignees()->attach($user);
    digestSetDue($nextWeek, $field, now()->addDays(3));

    $payload = resolve(DigestService::class)->forUser($user);

    expect($payload->taskCount())->toBe(2)
        ->and(collect($payload->workspaces[0]->overdue)->pluck('title')->all())->toBe(['overdue'])
        ->and(collect($payload->workspaces[0]->upcoming)->pluck('title')->all())->toBe(['today']);
});

it('excludes done tasks and tasks without a due date', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $field = digestDueField($workspace->id);
    [$statusField, $doneOption] = digestStatusDoneOption($workspace->id);

    $done = Task::factory()->for($workspace)->create(['title' => 'done']);
    $done->assignees()->attach($user);
    digestSetDue($done, $field, now()->subHour());
    digestSetStatus($done, $statusField, $doneOption);

    $noDue = Task::factory()->for($workspace)->create(['title' => 'no_due']);
    $noDue->assignees()->attach($user);

    $open = Task::factory()->for($workspace)->create(['title' => 'open']);
    $open->assignees()->attach($user);
    digestSetDue($open, $field, now()->subDay());

    $payload = resolve(DigestService::class)->forUser($user);

    expect($payload->taskCount())->toBe(1)
        ->and(collect($payload->workspaces[0]->overdue)->pluck('title')->all())->toBe(['open']);
});

it('groups tasks by workspace for multi-workspace users', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $user->currentWorkspace;
    $workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspaceB->users()->attach($user, ['role' => 'editor']);

    $a = Task::factory()->for($workspaceA)->create(['title' => 'a']);
    $a->assignees()->attach($user);
    digestSetDue($a, digestDueField($workspaceA->id), now());

    $b = Task::factory()->for($workspaceB)->create(['title' => 'b']);
    $b->assignees()->attach($user);
    digestSetDue($b, digestDueField($workspaceB->id), now());

    $payload = resolve(DigestService::class)->forUser($user);

    expect($payload->workspaces)->toHaveCount(2)
        ->and($payload->taskCount())->toBe(2);
});

it('computes the digest window in the recipient timezone, not the app timezone', function (): void {
    $this->travelTo(Date::parse('2026-06-28 23:00:00', 'UTC'));

    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = $user->currentWorkspace;
    $field = digestDueField($workspace->id);

    $task = Task::factory()->for($workspace)->create(['title' => 'tokyo_evening']);
    $task->assignees()->attach($user);
    digestSetDue($task, $field, Date::parse('2026-06-29 10:00:00', 'UTC'));

    $payload = resolve(DigestService::class)->forUser($user);

    expect($payload->taskCount())->toBe(1)
        ->and(collect($payload->workspaces[0]->upcoming)->pluck('title')->all())->toBe(['tokyo_evening']);
});

<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\MyTasksService;

mutates(MyTasksService::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

/**
 * Resolve the auto-seeded `due_date` custom field id for the given team.
 *
 * The `TeamCreated` listener seeds task custom fields, so this helper just looks them up.
 */
function resolveDueDateField(string $teamId): string
{
    $row = DB::table('custom_fields')
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->first();

    throw_if($row === null, RuntimeException::class, "Due date field not seeded for team {$teamId}");

    return trim((string) $row->id);
}

/**
 * Resolve the auto-seeded `status` custom field and its Done/To-do option ids.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function resolveStatusField(string $teamId): array
{
    $field = DB::table('custom_fields')
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->first();

    throw_if($field === null, RuntimeException::class, "Status field not seeded for team {$teamId}");

    $fieldId = trim((string) $field->id);

    $done = DB::table('custom_field_options')
        ->where('custom_field_id', $fieldId)
        ->where('name', 'Done')
        ->first();

    $todo = DB::table('custom_field_options')
        ->where('custom_field_id', $fieldId)
        ->where('name', 'To do')
        ->first();

    throw_if($done === null || $todo === null, RuntimeException::class, "Status options not seeded for team {$teamId}");

    return [$fieldId, trim((string) $done->id), trim((string) $todo->id)];
}

function attachDueDate(Task $task, string $fieldId, DateTimeInterface $dueAt): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $fieldId,
        'tenant_id' => $task->team_id,
        'datetime_value' => $dueAt->format('Y-m-d H:i:s'),
    ]);
}

function attachStatus(Task $task, string $statusFieldId, string $optionId): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $statusFieldId,
        'tenant_id' => $task->team_id,
        'string_value' => $optionId,
    ]);
}

it('returns an empty collection when the user has no tasks', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $items = (new MyTasksService)->forUser($user, $user->currentTeam);

    expect($items)->toBeEmpty();
});

it('only returns tasks assigned to the given user', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'editor']);

    $dueFieldId = resolveDueDateField($team->id);

    $mine = Task::factory()->for($team)->create(['title' => 'mine']);
    $mine->assignees()->attach($owner);
    attachDueDate($mine, $dueFieldId, now());

    $theirs = Task::factory()->for($team)->create(['title' => 'theirs']);
    $theirs->assignees()->attach($other);
    attachDueDate($theirs, $dueFieldId, now());

    $items = (new MyTasksService)->forUser($owner, $team);

    expect($items)->toHaveCount(1)
        ->and($items->first()->title)->toBe('mine');
});

it('includes tasks at any due date plus tasks without a due date', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $dueFieldId = resolveDueDateField($team->id);

    $cases = [
        'overdue' => now()->subDay(),
        'today' => now(),
        'tomorrow' => now()->addDay(),
        'far_future' => now()->addMonths(6),
    ];

    foreach ($cases as $label => $when) {
        $task = Task::factory()->for($team)->create(['title' => $label]);
        $task->assignees()->attach($user);
        attachDueDate($task, $dueFieldId, $when);
    }

    $noDate = Task::factory()->for($team)->create(['title' => 'no_due_date']);
    $noDate->assignees()->attach($user);

    $items = (new MyTasksService)->forUser($user, $team);

    expect($items->pluck('title')->all())
        ->toEqualCanonicalizing(['overdue', 'today', 'tomorrow', 'far_future', 'no_due_date']);
});

it('excludes tasks whose status is Done', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $dueFieldId = resolveDueDateField($team->id);
    [$statusFieldId, $doneId, $todoId] = resolveStatusField($team->id);

    $done = Task::factory()->for($team)->create(['title' => 'done']);
    $done->assignees()->attach($user);
    attachDueDate($done, $dueFieldId, now()->subHour());
    attachStatus($done, $statusFieldId, $doneId);

    $open = Task::factory()->for($team)->create(['title' => 'open']);
    $open->assignees()->attach($user);
    attachDueDate($open, $dueFieldId, now()->subHour());
    attachStatus($open, $statusFieldId, $todoId);

    $noStatus = Task::factory()->for($team)->create(['title' => 'no_status']);
    $noStatus->assignees()->attach($user);
    attachDueDate($noStatus, $dueFieldId, now()->subHour());

    $items = (new MyTasksService)->forUser($user, $team);

    expect($items->pluck('title')->all())
        ->toEqualCanonicalizing(['open', 'no_status']);
});

it('sorts ascending by due date and tags severity correctly', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $dueFieldId = resolveDueDateField($team->id);

    $a = Task::factory()->for($team)->create(['title' => 'a']);
    $a->assignees()->attach($user);
    attachDueDate($a, $dueFieldId, now()->subDay());

    $b = Task::factory()->for($team)->create(['title' => 'b']);
    $b->assignees()->attach($user);
    attachDueDate($b, $dueFieldId, now()->setTime(14, 0));

    $c = Task::factory()->for($team)->create(['title' => 'c']);
    $c->assignees()->attach($user);
    attachDueDate($c, $dueFieldId, now()->addDay());

    $items = (new MyTasksService)->forUser($user, $team)->values();

    expect($items->pluck('title')->all())->toBe(['a', 'b', 'c'])
        ->and($items[0]->severity)->toBe('overdue')
        ->and($items[1]->severity)->toBe('today')
        ->and($items[2]->severity)->toBe('tomorrow');
});

it('caps results at five tasks', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $dueFieldId = resolveDueDateField($team->id);

    foreach (range(1, 8) as $i) {
        $task = Task::factory()->for($team)->create(['title' => "t{$i}"]);
        $task->assignees()->attach($user);
        attachDueDate($task, $dueFieldId, now()->subMinutes($i));
    }

    $items = (new MyTasksService)->forUser($user, $team);

    expect($items)->toHaveCount(5);
});

it('does not leak tasks from another team where the user is also a member', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamA = $user->currentTeam;
    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->users()->attach($user, ['role' => 'editor']);

    $dueFieldB = resolveDueDateField($teamB->id);

    $leaked = Task::factory()->for($teamB)->create(['title' => 'leaked']);
    $leaked->assignees()->attach($user);
    attachDueDate($leaked, $dueFieldB, now());

    $items = (new MyTasksService)->forUser($user, $teamA);

    expect($items)->toBeEmpty();
});

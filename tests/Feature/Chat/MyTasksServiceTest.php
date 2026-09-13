<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\MyTasksService;

mutates(MyTasksService::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

/**
 * Resolve the auto-seeded `due_date` custom field id for the given workspace.
 *
 * The `WorkspaceCreated` listener seeds task custom fields, so this helper just looks them up.
 */
function resolveDueDateField(string $workspaceId): string
{
    $row = DB::table('custom_fields')
        ->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->first();

    throw_if($row === null, RuntimeException::class, "Due date field not seeded for workspace {$workspaceId}");

    return trim((string) $row->id);
}

/**
 * Resolve the auto-seeded `status` custom field and its Done/To-do option ids.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function resolveStatusField(string $workspaceId): array
{
    $field = DB::table('custom_fields')
        ->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->first();

    throw_if($field === null, RuntimeException::class, "Status field not seeded for workspace {$workspaceId}");

    $fieldId = trim((string) $field->id);

    $done = DB::table('custom_field_options')
        ->where('custom_field_id', $fieldId)
        ->where('name', 'Done')
        ->first();

    $todo = DB::table('custom_field_options')
        ->where('custom_field_id', $fieldId)
        ->where('name', 'To do')
        ->first();

    throw_if($done === null || $todo === null, RuntimeException::class, "Status options not seeded for workspace {$workspaceId}");

    return [$fieldId, trim((string) $done->id), trim((string) $todo->id)];
}

function attachDueDate(Task $task, string $fieldId, DateTimeInterface $dueAt): void
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

function attachStatus(Task $task, string $statusFieldId, string $optionId): void
{
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $statusFieldId,
        'tenant_id' => $task->workspace_id,
        'string_value' => $optionId,
    ]);
}

it('returns an empty collection when the user has no tasks', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $items = (new MyTasksService)->forUser($user, $user->currentWorkspace);

    expect($items)->toBeEmpty();
});

it('only returns tasks assigned to the given user', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $other = User::factory()->create();
    $workspace->users()->attach($other, ['role' => 'editor']);

    $dueFieldId = resolveDueDateField($workspace->id);

    $mine = Task::factory()->for($workspace)->create(['title' => 'mine']);
    $mine->assignees()->attach($owner);
    attachDueDate($mine, $dueFieldId, now());

    $theirs = Task::factory()->for($workspace)->create(['title' => 'theirs']);
    $theirs->assignees()->attach($other);
    attachDueDate($theirs, $dueFieldId, now());

    $items = (new MyTasksService)->forUser($owner, $workspace);

    expect($items)->toHaveCount(1)
        ->and($items->first()->title)->toBe('mine');
});

it('includes tasks at any due date plus tasks without a due date', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    $cases = [
        'overdue' => now()->subDay(),
        'today' => now(),
        'tomorrow' => now()->addDay(),
        'far_future' => now()->addMonths(6),
    ];

    foreach ($cases as $label => $when) {
        $task = Task::factory()->for($workspace)->create(['title' => $label]);
        $task->assignees()->attach($user);
        attachDueDate($task, $dueFieldId, $when);
    }

    $noDate = Task::factory()->for($workspace)->create(['title' => 'no_due_date']);
    $noDate->assignees()->attach($user);

    $items = (new MyTasksService)->forUser($user, $workspace);

    expect($items->pluck('title')->all())
        ->toEqualCanonicalizing(['overdue', 'today', 'tomorrow', 'far_future', 'no_due_date']);
});

it('excludes tasks whose status is Done', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $dueFieldId = resolveDueDateField($workspace->id);
    [$statusFieldId, $doneId, $todoId] = resolveStatusField($workspace->id);

    $done = Task::factory()->for($workspace)->create(['title' => 'done']);
    $done->assignees()->attach($user);
    attachDueDate($done, $dueFieldId, now()->subHour());
    attachStatus($done, $statusFieldId, $doneId);

    $open = Task::factory()->for($workspace)->create(['title' => 'open']);
    $open->assignees()->attach($user);
    attachDueDate($open, $dueFieldId, now()->subHour());
    attachStatus($open, $statusFieldId, $todoId);

    $noStatus = Task::factory()->for($workspace)->create(['title' => 'no_status']);
    $noStatus->assignees()->attach($user);
    attachDueDate($noStatus, $dueFieldId, now()->subHour());

    $items = (new MyTasksService)->forUser($user, $workspace);

    expect($items->pluck('title')->all())
        ->toEqualCanonicalizing(['open', 'no_status']);
});

it('sorts ascending by due date and tags severity correctly', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    $a = Task::factory()->for($workspace)->create(['title' => 'a']);
    $a->assignees()->attach($user);
    attachDueDate($a, $dueFieldId, now()->subDay());

    $b = Task::factory()->for($workspace)->create(['title' => 'b']);
    $b->assignees()->attach($user);
    attachDueDate($b, $dueFieldId, now()->setTime(14, 0));

    $c = Task::factory()->for($workspace)->create(['title' => 'c']);
    $c->assignees()->attach($user);
    attachDueDate($c, $dueFieldId, now()->addDay());

    $items = (new MyTasksService)->forUser($user, $workspace)->values();

    expect($items->pluck('title')->all())->toBe(['a', 'b', 'c'])
        ->and($items[0]->severity)->toBe('overdue')
        ->and($items[1]->severity)->toBe('today')
        ->and($items[2]->severity)->toBe('tomorrow');
});

it('caps results at five tasks', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    foreach (range(1, 8) as $i) {
        $task = Task::factory()->for($workspace)->create(['title' => "t{$i}"]);
        $task->assignees()->attach($user);
        attachDueDate($task, $dueFieldId, now()->subMinutes($i));
    }

    $items = (new MyTasksService)->forUser($user, $workspace);

    expect($items)->toHaveCount(5);
});

it('does not leak tasks from another workspace where the user is also a member', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $user->currentWorkspace;
    $workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspaceB->users()->attach($user, ['role' => 'editor']);

    $dueFieldB = resolveDueDateField($workspaceB->id);

    $leaked = Task::factory()->for($workspaceB)->create(['title' => 'leaked']);
    $leaked->assignees()->attach($user);
    attachDueDate($leaked, $dueFieldB, now());

    $items = (new MyTasksService)->forUser($user, $workspaceA);

    expect($items)->toBeEmpty();
});

it('bounds today on the user calendar, not the server clock', function (): void {
    // At 2026-08-18 23:30 UTC the Tokyo calendar already reads the 19th, so Tokyo's
    // "today" began at 15:00 UTC. A task due 10:00 UTC that day therefore falls before
    // it (overdue) while for a UTC reader at the very same instant it is still today.
    $this->travelTo(Date::parse('2026-08-18 23:30:00', 'UTC'));

    $tokyo = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = $tokyo->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    $task = Task::factory()->for($workspace)->create(['title' => 'crosses midnight in Tokyo']);
    $task->assignees()->attach($tokyo);
    attachDueDate($task, $dueFieldId, Date::parse('2026-08-18 10:00:00', 'UTC'));

    expect((new MyTasksService)->forUser($tokyo, $workspace)->first()->severity)->toBe('overdue');
});

it('reads the same task as due today for a user whose calendar has not rolled over', function (): void {
    $this->travelTo(Date::parse('2026-08-18 23:30:00', 'UTC'));

    $london = User::factory()->withPersonalWorkspace()->create(['timezone' => 'UTC']);
    $workspace = $london->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    $task = Task::factory()->for($workspace)->create(['title' => 'still today in UTC']);
    $task->assignees()->attach($london);
    attachDueDate($task, $dueFieldId, Date::parse('2026-08-18 10:00:00', 'UTC'));

    expect((new MyTasksService)->forUser($london, $workspace)->first()->severity)->toBe('today');
});

it('ends today at local midnight across a dst transition, not 24 hours after it starts', function (): void {
    // The UK puts its clocks back at 02:00 on 2026-10-25, so that local day runs 25
    // hours: it starts at 23:00 UTC on the 24th and ends at 00:00 UTC on the 26th.
    // Adding a day to the converted start would cut "today" off at 23:00 UTC and push
    // a task due half an hour later into tomorrow.
    $this->travelTo(Date::parse('2026-10-25 10:00:00', 'Europe/London'));

    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Europe/London']);
    $workspace = $user->currentWorkspace;
    $dueFieldId = resolveDueDateField($workspace->id);

    $task = Task::factory()->for($workspace)->create(['title' => 'late on the long day']);
    $task->assignees()->attach($user);
    attachDueDate($task, $dueFieldId, Date::parse('2026-10-25 23:30:00', 'Europe/London')->utc());

    expect((new MyTasksService)->forUser($user, $workspace)->first()->severity)->toBe('today');
});

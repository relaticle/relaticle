<?php

declare(strict_types=1);

use App\Data\DigestRecord;
use App\Enums\CrmEntity;
use App\Features\OnboardSeed;
use App\Filament\Resources\TaskResource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
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

function digestSetOption(Task $task, string $fieldCode, string $optionName): void
{
    $field = DB::table('custom_fields')->where('tenant_id', $task->workspace_id)
        ->where('entity_type', 'task')->where('code', $fieldCode)->first();
    $option = DB::table('custom_field_options')->where('custom_field_id', trim((string) $field->id))->where('name', $optionName)->first();

    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => trim((string) $field->id),
        'tenant_id' => $task->workspace_id,
        'string_value' => trim((string) $option->id),
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

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);

    expect(collect($digest->overdue)->pluck('title')->all())->toBe(['overdue'])
        ->and(collect($digest->upcoming)->pluck('title')->all())->toBe(['today']);
});

it('links each digest task to its edit modal in the task workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $task = Task::factory()->for($workspace)->create(['title' => 'overdue']);
    $task->assignees()->attach($user);
    digestSetDue($task, digestDueField($workspace->id), now()->subDay());

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);

    expect($digest->overdue[0]->editUrl)->toBe(TaskResource::getUrl('index', ['task' => $task->id], panel: 'app', tenant: $workspace));
});

it('names the company, person and opportunity linked to each digest task, skipping deleted ones', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $task = Task::factory()->for($workspace)->create(['title' => 'linked']);
    $task->assignees()->attach($user);
    digestSetDue($task, digestDueField($workspace->id), now()->subDay());

    $task->companies()->attach(Company::factory()->for($workspace)->create(['name' => 'Northwind']));
    $task->people()->attach(People::factory()->for($workspace)->create(['name' => 'Ana Reyes']));
    $task->opportunities()->attach(Opportunity::factory()->for($workspace)->create(['name' => 'Fleet renewal']));
    $deleted = Company::factory()->for($workspace)->create(['name' => 'Gone Corp']);
    $task->companies()->attach($deleted);
    $deleted->delete();

    $unlinked = Task::factory()->for($workspace)->create(['title' => 'unlinked']);
    $unlinked->assignees()->attach($user);
    digestSetDue($unlinked, digestDueField($workspace->id), now()->subDay());

    $overdue = collect(resolve(DigestService::class)->forWorkspace($user, $workspace)->overdue)->keyBy('title');

    expect(array_map(fn (DigestRecord $record): array => [$record->entity, $record->name], $overdue['linked']->records))->toBe([
        [CrmEntity::Company, 'Northwind'],
        [CrmEntity::People, 'Ana Reyes'],
        [CrmEntity::Opportunity, 'Fleet renewal'],
    ])
        ->and($overdue['unlinked']->records)->toBe([]);
});

it('carries each digest task priority and its option color', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $urgent = Task::factory()->for($workspace)->create(['title' => 'urgent']);
    $urgent->assignees()->attach($user);
    digestSetDue($urgent, digestDueField($workspace->id), now()->subDay());
    digestSetOption($urgent, 'priority', 'High');

    $plain = Task::factory()->for($workspace)->create(['title' => 'plain']);
    $plain->assignees()->attach($user);
    digestSetDue($plain, digestDueField($workspace->id), now()->subDay());

    $overdue = collect(resolve(DigestService::class)->forWorkspace($user, $workspace)->overdue)->keyBy('title');

    expect($overdue['urgent']->priority)->toBe('High')
        ->and($overdue['urgent']->priorityColor)->toBe('#dc2626')
        ->and($overdue['plain']->priority)->toBeNull();
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

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);

    expect($digest->upcoming)->toBe([])
        ->and(collect($digest->overdue)->pluck('title')->all())->toBe(['open']);
});

it('builds each workspace digest from that workspace tasks only', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $user->currentWorkspace;
    $workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $workspaceB->users()->attach($user, ['role' => 'member']);

    $a = Task::factory()->for($workspaceA)->create(['title' => 'a']);
    $a->assignees()->attach($user);
    digestSetDue($a, digestDueField($workspaceA->id), now());

    $b = Task::factory()->for($workspaceB)->create(['title' => 'b']);
    $b->assignees()->attach($user);
    digestSetDue($b, digestDueField($workspaceB->id), now());

    $service = resolve(DigestService::class);

    expect(collect($service->forWorkspace($user, $workspaceA)->upcoming)->pluck('title')->all())->toBe(['a'])
        ->and(collect($service->forWorkspace($user, $workspaceB)->upcoming)->pluck('title')->all())->toBe(['b']);
});

it('computes the digest window in the recipient timezone, not the app timezone', function (): void {
    $this->travelTo(Date::parse('2026-06-28 23:00:00', 'UTC'));

    $user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = $user->currentWorkspace;
    $field = digestDueField($workspace->id);

    $task = Task::factory()->for($workspace)->create(['title' => 'tokyo_evening']);
    $task->assignees()->attach($user);
    digestSetDue($task, $field, Date::parse('2026-06-29 10:00:00', 'UTC'));

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);

    expect($digest->overdue)->toBe([])
        ->and(collect($digest->upcoming)->pluck('title')->all())->toBe(['tokyo_evening']);
});

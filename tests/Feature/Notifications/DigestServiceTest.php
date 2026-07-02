<?php

declare(strict_types=1);

use App\Enums\Notifications\DigestCadence;
use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\DigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

function digestDueField(string $teamId): string
{
    $row = DB::table('custom_fields')->where('tenant_id', $teamId)
        ->where('entity_type', 'task')->where('code', 'due_date')->first();
    throw_if($row === null, RuntimeException::class, "due_date not seeded for {$teamId}");

    return trim((string) $row->id);
}

/** @return array{0: string, 1: string} */
function digestStatusDoneOption(string $teamId): array
{
    $field = DB::table('custom_fields')->where('tenant_id', $teamId)
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
        'tenant_id' => $task->team_id,
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
        'tenant_id' => $task->team_id,
        'string_value' => $optionId,
    ]);
}

it('daily digest contains overdue and due-today tasks only', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $field = digestDueField($team->id);

    $overdue = Task::factory()->for($team)->create(['title' => 'overdue']);
    $overdue->assignees()->attach($user);
    digestSetDue($overdue, $field, now()->subDay());

    $today = Task::factory()->for($team)->create(['title' => 'today']);
    $today->assignees()->attach($user);
    digestSetDue($today, $field, now()->setTime(15, 0));

    $nextWeek = Task::factory()->for($team)->create(['title' => 'next_week']);
    $nextWeek->assignees()->attach($user);
    digestSetDue($nextWeek, $field, now()->addDays(3));

    $payload = resolve(DigestService::class)->forUser($user, DigestCadence::Daily);

    expect($payload->taskCount())->toBe(2)
        ->and(collect($payload->teams[0]->overdue)->pluck('title')->all())->toBe(['overdue'])
        ->and(collect($payload->teams[0]->upcoming)->pluck('title')->all())->toBe(['today']);
});

it('weekly digest extends the window to seven days', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $field = digestDueField($team->id);

    foreach (['overdue' => now()->subDay(), 'today' => now()->setTime(9, 0), 'in_three' => now()->addDays(3), 'out_of_window' => now()->addDays(8)] as $title => $when) {
        $t = Task::factory()->for($team)->create(['title' => $title]);
        $t->assignees()->attach($user);
        digestSetDue($t, $field, $when);
    }

    $payload = resolve(DigestService::class)->forUser($user, DigestCadence::Weekly);

    expect($payload->taskCount())->toBe(3);
});

it('excludes done tasks and tasks without a due date', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $field = digestDueField($team->id);
    [$statusField, $doneOption] = digestStatusDoneOption($team->id);

    $done = Task::factory()->for($team)->create(['title' => 'done']);
    $done->assignees()->attach($user);
    digestSetDue($done, $field, now()->subHour());
    digestSetStatus($done, $statusField, $doneOption);

    $noDue = Task::factory()->for($team)->create(['title' => 'no_due']);
    $noDue->assignees()->attach($user);

    $open = Task::factory()->for($team)->create(['title' => 'open']);
    $open->assignees()->attach($user);
    digestSetDue($open, $field, now()->subDay());

    $payload = resolve(DigestService::class)->forUser($user, DigestCadence::Daily);

    expect($payload->taskCount())->toBe(1)
        ->and(collect($payload->teams[0]->overdue)->pluck('title')->all())->toBe(['open']);
});

it('groups tasks by team for multi-team users', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamA = $user->currentTeam;
    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->users()->attach($user, ['role' => 'editor']);

    $a = Task::factory()->for($teamA)->create(['title' => 'a']);
    $a->assignees()->attach($user);
    digestSetDue($a, digestDueField($teamA->id), now());

    $b = Task::factory()->for($teamB)->create(['title' => 'b']);
    $b->assignees()->attach($user);
    digestSetDue($b, digestDueField($teamB->id), now());

    $payload = resolve(DigestService::class)->forUser($user, DigestCadence::Daily);

    expect($payload->teams)->toHaveCount(2)
        ->and($payload->taskCount())->toBe(2);
});

<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

mutates(ManageTasks::class, NotifyTaskAssignees::class, TaskResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(ManageTasks::class)
        ->assertOk();
});

// Column metadata is checked against a single mounted table rather than one
// dataset case per column: mounting the page dominates the cost, and Filament's
// assertion messages already name the offending column.
it('exposes the expected table columns', function (): void {
    $table = livewire(ManageTasks::class);

    foreach (['title', 'assignees.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at'] as $column) {
        $table->assertTableColumnExists($column);
    }

    foreach (['title', 'assignees.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at'] as $column) {
        $table->assertTableColumnVisible($column);
    }

    foreach (['title', 'creator.name'] as $column) {
        $table->assertCanRenderTableColumn($column);
    }

    foreach (['assignees.name', 'created_at', 'updated_at', 'deleted_at'] as $column) {
        $table->assertCanNotRenderTableColumn($column);
    }
});

it('can sort `:dataset` column', function (string $column): void {
    $records = Task::factory(3)->recycle([$this->user, $this->team])->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ManageTasks::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = Task::factory(3)->recycle([$this->user, $this->team])->create();
    $search = data_get($records->first(), $column);

    livewire(ManageTasks::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['title', 'assignees.name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Task::factory()->count(4)->recycle([$this->user, $this->team])->create();
    $trashedRecords = Task::factory()->trashed()->count(6)->recycle([$this->user, $this->team])->create();

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Task::factory(20)->recycle([$this->user, $this->team])->create();

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Task::factory(5)->recycle([$this->user, $this->team])->create();

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create a task', function (): void {
    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'New Task',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Task::class, [
        'title' => 'New Task',
        'team_id' => $this->team->id,
    ]);
});

// The create action reads assignee ids straight off submitted form state, and form
// state is client-controlled: nothing stops a hand-crafted Livewire payload naming a
// user from another workspace. The option query is what rejects it, because Filament
// builds the field's `in` rule from that same query.
it('rejects a create payload naming an assignee outside the workspace', function (): void {
    $this->withoutDefer();

    $outsider = User::factory()->withTeam()->create();

    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'Outsider Task',
            'assignees' => [$outsider->id],
        ])
        ->assertHasActionErrors(['assignees.0']);

    expect(Task::query()->where('title', 'Outsider Task')->exists())->toBeFalse()
        ->and($outsider->notifications()->count())->toBe(0);
});

it('notifies a newly assigned member with a deep-link that opens the task edit modal', function (): void {
    $this->withoutDefer();

    $assignee = User::factory()->create();
    $this->team->users()->attach($assignee, ['role' => 'admin']);

    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'Assigned Task',
            'assignees' => [$assignee->id],
        ])
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'Assigned Task')->sole();
    $url = data_get($assignee->notifications()->sole()->data, 'actions.0.url');

    expect($url)->toContain('/tasks')
        ->and($url)->toContain('tableAction=edit')
        ->and($url)->toContain('tableActionRecord='.$task->getKey());
});

it('notifies only the assignees submitted through the create action', function (): void {
    Mail::fake();

    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'editor']);

    $concurrentAssignmentAdded = false;
    DB::listen(function (QueryExecuted $query) use ($concurrentAssignee, &$concurrentAssignmentAdded): void {
        if ($concurrentAssignmentAdded || ! str_contains($query->sql, 'insert into "task_user"')) {
            return;
        }

        $taskId = DB::table('tasks')->where('title', 'Filament notification race')->value('id');

        if (! is_string($taskId)) {
            return;
        }

        $concurrentAssignmentAdded = true;
        DB::table('task_user')->insert([
            'task_id' => $taskId,
            'user_id' => $concurrentAssignee->id,
        ]);
    });

    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'Filament notification race',
            'assignees' => [$intendedAssignee->id],
        ])
        ->assertHasNoActionErrors();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($intendedAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($concurrentAssignee->email));
});

it('can edit a task', function (): void {
    $record = Task::factory()->recycle([$this->user, $this->team])->create();

    livewire(ManageTasks::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'title' => 'Updated Task',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Updated Task');
});

it('can delete a task', function (): void {
    $record = Task::factory()->recycle([$this->user, $this->team])->create();

    livewire(ManageTasks::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates title is required on create', function (): void {
    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => null,
        ])
        ->assertHasActionErrors(['title' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ManageTasks::class)
        ->assertTableFilterExists($filter);
})->with(['assigned_to_me', 'assignees', 'creation_source', 'trashed']);

it('sets creator_id and team_id via observer when creating a task', function (): void {
    livewire(ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'Observer Test Task',
        ])
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'Observer Test Task')->first();

    expect($task->creator_id)->toBe($this->user->id)
        ->and($task->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team task', function (): void {
    $record = Task::factory()->recycle([$this->user, $this->team])->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team task', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = Task::factory()->for($otherTeam)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

/**
 * The picker resolves its timezone from the same TimezoneManager the table columns read,
 * so display and entry cannot drift apart. This asserts the *input* direction, where a
 * regression would silently shift every datetime a user types.
 */
it('stores a datetime typed in the user timezone as utc', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    // A real request has the panel bound by Filament's middleware; the harness does not,
    // and the conversion is deliberately scoped to the app panel.
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $dueField = DB::table('custom_fields')
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->value('id');

    expect($dueField)->not->toBeNull();

    livewire(ManageTasks::class)
        ->callAction(TestAction::make('create'), [
            'title' => 'typed in Tokyo',
            'custom_fields' => ['due_date' => '2026-08-19 08:30:00'],
        ])
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'typed in Tokyo')->sole();

    $stored = DB::table('custom_field_values')
        ->where('entity_id', $task->getKey())
        ->where('entity_type', 'task')
        ->where('custom_field_id', $dueField)
        ->value('datetime_value');

    // 08:30 on the 19th in Tokyo is 23:30 the previous evening in UTC.
    expect($stored)->toBe('2026-08-18 23:30:00');
});

/**
 * The other half of the round trip: a due date typed in the user's zone has to come
 * back out of the table reading the same wall clock it was entered with. The list is
 * the primary view of a task, so a shift here is what a customer actually sees.
 */
it('renders a custom-field datetime in the user timezone in the table', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(ManageTasks::class)
        ->callAction(TestAction::make('create'), [
            'title' => 'round trips through Tokyo',
            'custom_fields' => ['due_date' => '2026-08-19 08:30:00'],
        ])
        ->assertHasNoActionErrors();

    livewire(ManageTasks::class)
        ->assertOk()
        ->assertSee('Aug 19, 2026 08:30')
        ->assertDontSee('Aug 18, 2026 23:30');
});

/**
 * A custom-field datetime has to read like the `created_at` next to it, so the column
 * takes the table's format rather than one of its own. The seconds are the tell: the
 * table default carries them, and the literal this class used to hardcode did not.
 */
it('renders a custom-field datetime in the same format as the table default', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(ManageTasks::class)
        ->callAction(TestAction::make('create'), [
            'title' => 'formatted like its neighbours',
            'custom_fields' => ['due_date' => '2026-08-19 08:30:00'],
        ])
        ->assertHasNoActionErrors();

    $format = Table::make(new ManageTasks)->getDefaultDateTimeDisplayFormat();

    livewire(ManageTasks::class)
        ->assertOk()
        ->assertSee(Date::parse('2026-08-19 08:30:00', 'Asia/Tokyo')->translatedFormat($format));
});

<?php

declare(strict_types=1);

use App\Filament\Components\Forms\WorkspaceMemberSelect;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

mutates(WorkspaceMemberSelect::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('still saves task assignees through the pivot after the migration', function (): void {
    $mate = User::factory()->create(['name' => 'Mate Member']);
    $this->workspace->users()->attach($mate, ['role' => 'member']);

    livewire(ManageTasks::class)
        ->callAction('create', [
            'title' => 'Picker regression task',
            'assignees' => [$mate->getKey()],
        ])
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'Picker regression task')->firstOrFail();

    expect($task->assignees->pluck('id')->all())->toContain($mate->getKey());
});

it('keeps the assignee rule usable and bounded on the tasks list', function (): void {
    $mate = User::factory()->create(['name' => 'Mate Member']);
    $this->workspace->users()->attach($mate, ['role' => 'member']);

    $assignedToMate = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $assignedToMate->assignees()->attach($mate);

    $assignedToUser = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $assignedToUser->assignees()->attach($this->user);

    $stranger = User::factory()->create(['name' => 'Stranger Danger']);

    livewire(ManageTasks::class)
        ->assertTableFilterExists('conditions')
        ->assertOk()
        ->set('tableFilters.conditions.rules', [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => [$mate->getKey()]]])
        ->assertCanSeeTableRecords([$assignedToMate])
        ->assertCanNotSeeTableRecords([$assignedToUser])
        ->set('tableFilters.conditions.rules', [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => [$stranger->getKey()]]])
        ->assertSet('tableFilters.conditions.rules', [])
        ->assertCanSeeTableRecords([$assignedToMate, $assignedToUser]);
});

it('runs the bounded assignee query when the tasks board column form validates its assignees without a Postgres distinct/order-by conflict', function (): void {
    $mate = User::factory()->create(['name' => 'Mate Member']);
    $this->workspace->users()->attach($mate, ['role' => 'member']);

    DB::enableQueryLog();

    $component = livewire(TasksBoard::class);
    $column = $component->instance()->getBoard()->getColumnIdentifiers()[0];

    $component
        ->call('mountAction', 'addCard', ['column' => $column])
        ->set('mountedActions.0.data.title', 'Board picker task')
        ->set('mountedActions.0.data.assignees', [$mate->getKey()])
        ->call('callMountedAction')
        ->assertOk()
        ->assertHasNoErrors();

    $pinnedOrderQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'workspace_member_select_is_current_user'));

    DB::disableQueryLog();

    expect($pinnedOrderQueries)->not->toBeEmpty();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertCanRenderTableColumn($column);
})->with(['title', 'creator.name']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['assignees.name', 'created_at', 'updated_at', 'deleted_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertTableColumnExists($column);
})->with(['title', 'assignees.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertTableColumnVisible($column);
})->with(['title', 'assignees.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = App\Models\Task::factory(3)->for($this->team)->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column)->value
        : $column;

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = App\Models\Task::factory(3)->for($this->team)->create();
    $search = data_get($records->first(), $column);

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column) !== $search));
})->with(['title', 'assignees.name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = App\Models\Task::factory()->count(4)->for($this->team)->create();
    $trashedRecords = App\Models\Task::factory()->trashed()->count(6)->for($this->team)->create();

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = App\Models\Task::factory(20)->for($this->team)->create();

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = App\Models\Task::factory(5)->for($this->team)->create();

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
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
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->callAction('create', data: [
            'title' => 'New Task',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(App\Models\Task::class, [
        'title' => 'New Task',
        'team_id' => $this->team->id,
    ]);
});

it('can edit a task', function (): void {
    $record = App\Models\Task::factory()->for($this->team)->create();

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'title' => 'Updated Task',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Updated Task');
});

it('can delete a task', function (): void {
    $record = App\Models\Task::factory()->for($this->team)->create();

    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates title is required on create', function (): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->callAction('create', data: [
            'title' => null,
        ])
        ->assertHasActionErrors(['title' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertTableFilterExists($filter);
})->with(['assigned_to_me', 'assignees', 'creation_source', 'trashed']);

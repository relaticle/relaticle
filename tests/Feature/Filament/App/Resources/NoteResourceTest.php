<?php

declare(strict_types=1);

use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

mutates(NoteResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(ManageNotes::class)
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(ManageNotes::class)
        ->assertCanRenderTableColumn($column);
})->with(['title', 'companies.name', 'people.name', 'creator.name', 'created_at']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(ManageNotes::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['deleted_at', 'updated_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(ManageNotes::class)
        ->assertTableColumnExists($column);
})->with(['title', 'companies.name', 'people.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(ManageNotes::class)
        ->assertTableColumnVisible($column);
})->with(['title', 'companies.name', 'people.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = Note::factory(3)->for($this->team)->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ManageNotes::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = Note::factory(3)->for($this->team)->create();
    $search = data_get($records->first(), $column);

    livewire(ManageNotes::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['title', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Note::factory()->count(4)->for($this->team)->create();
    $trashedRecords = Note::factory()->trashed()->count(6)->for($this->team)->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Note::factory(20)->for($this->team)->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Note::factory(5)->for($this->team)->create();

    livewire(ManageNotes::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create a note', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'New Note',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Note::class, [
        'title' => 'New Note',
        'team_id' => $this->team->id,
    ]);
});

it('can edit a note', function (): void {
    $record = Note::factory()->for($this->team)->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'title' => 'Updated Note',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->title)->toBe('Updated Note');
});

it('can delete a note', function (): void {
    $record = Note::factory()->for($this->team)->create();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates title is required on create', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => null,
        ])
        ->assertHasActionErrors(['title' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ManageNotes::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

it('sets creator_id and team_id via observer when creating a note', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', data: [
            'title' => 'Observer Test Note',
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'Observer Test Note')->first();

    expect($note->creator_id)->toBe($this->user->id)
        ->and($note->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team note', function (): void {
    $record = Note::factory()->for($this->team)->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team note', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = Note::factory()->for($otherTeam)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

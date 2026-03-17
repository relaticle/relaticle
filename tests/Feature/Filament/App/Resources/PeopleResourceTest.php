<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

mutates(PeopleResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(ListPeople::class)
        ->assertOk();
});

it('can render the view page', function (): void {
    $record = People::factory()->for($this->team)->create();

    livewire(ViewPeople::class, ['record' => $record->getKey()])
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(ListPeople::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'company.name', 'creator.name']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(ListPeople::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['created_at', 'updated_at', 'deleted_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(ListPeople::class)
        ->assertTableColumnExists($column);
})->with(['name', 'company.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(ListPeople::class)
        ->assertTableColumnVisible($column);
})->with(['name', 'company.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = People::factory(3)->for($this->team)->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ListPeople::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['company.name', 'creator.name', 'created_at', 'updated_at', 'deleted_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = People::factory(3)->for($this->team)->create();
    $search = data_get($records->first(), $column);

    livewire(ListPeople::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['name', 'company.name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = People::factory()->count(4)->for($this->team)->create();
    $trashedRecords = People::factory()->trashed()->count(6)->for($this->team)->create();

    livewire(ListPeople::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = People::factory(20)->for($this->team)->create();

    livewire(ListPeople::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = People::factory(5)->for($this->team)->create();

    livewire(ListPeople::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create a person', function (): void {
    livewire(ListPeople::class)
        ->callAction('create', data: [
            'name' => 'Jane Doe',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(People::class, [
        'name' => 'Jane Doe',
        'team_id' => $this->team->id,
    ]);
});

it('can edit a person', function (): void {
    $record = People::factory()->for($this->team)->create();

    livewire(ListPeople::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'name' => 'Updated Person',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->name)->toBe('Updated Person');
});

it('can delete a person', function (): void {
    $record = People::factory()->for($this->team)->create();

    livewire(ListPeople::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates name is required on create', function (): void {
    livewire(ListPeople::class)
        ->callAction('create', data: [
            'name' => null,
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ListPeople::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

it('sets creator_id and team_id via observer when creating a person', function (): void {
    livewire(ListPeople::class)
        ->callAction('create', data: [
            'name' => 'Observer Test Person',
        ])
        ->assertHasNoActionErrors();

    $person = People::query()->where('name', 'Observer Test Person')->first();

    expect($person->creator_id)->toBe($this->user->id)
        ->and($person->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team person', function (): void {
    $record = People::factory()->for($this->team)->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team person', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = People::factory()->for($otherTeam)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

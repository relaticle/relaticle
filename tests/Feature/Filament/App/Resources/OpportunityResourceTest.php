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
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertOk();
});

it('can render the view page', function (): void {
    $record = App\Models\Opportunity::factory()->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'creator.name']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['deleted_at', 'created_at', 'updated_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertTableColumnExists($column);
})->with(['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertTableColumnVisible($column);
})->with(['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = App\Models\Opportunity::factory(3)->for($this->team)->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column)->value
        : $column;

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = App\Models\Opportunity::factory(3)->for($this->team)->create();
    $search = data_get($records->first(), $column);

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Illuminate\Database\Eloquent\Model $record) => data_get($record, $column) !== $search));
})->with(['name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = App\Models\Opportunity::factory()->count(4)->for($this->team)->create();
    $trashedRecords = App\Models\Opportunity::factory()->trashed()->count(6)->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = App\Models\Opportunity::factory(20)->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = App\Models\Opportunity::factory(5)->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create an opportunity', function (): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->callAction('create', data: [
            'name' => 'Big Deal',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(App\Models\Opportunity::class, [
        'name' => 'Big Deal',
        'team_id' => $this->team->id,
    ]);
});

it('can edit an opportunity', function (): void {
    $record = App\Models\Opportunity::factory()->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'name' => 'Updated Opportunity',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->name)->toBe('Updated Opportunity');
});

it('can delete an opportunity', function (): void {
    $record = App\Models\Opportunity::factory()->for($this->team)->create();

    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates name is required on create', function (): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->callAction('create', data: [
            'name' => null,
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(App\Filament\Resources\OpportunityResource\Pages\ListOpportunities::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

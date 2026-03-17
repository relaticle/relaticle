<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

mutates(CompanyResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(ListCompanies::class)
        ->assertOk();
});

it('can render the view page', function (): void {
    $record = Company::factory()->for($this->team)->create();

    livewire(ViewCompany::class, ['record' => $record->getKey()])
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(ListCompanies::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'accountOwner.name', 'creator.name', 'created_at', 'updated_at']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(ListCompanies::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['deleted_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(ListCompanies::class)
        ->assertTableColumnExists($column);
})->with(['name', 'accountOwner.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(ListCompanies::class)
        ->assertTableColumnVisible($column);
})->with(['name', 'accountOwner.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = Company::factory(3)->for($this->team)->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ListCompanies::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['name', 'accountOwner.name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = Company::factory(3)->for($this->team)->create();
    $search = data_get($records->first(), $column);

    $visibleRecords = $records->filter(fn (Model $record) => data_get($record, $column) === $search);

    livewire(ListCompanies::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($visibleRecords)
        ->assertCountTableRecords($visibleRecords->count());
})->with(['name', 'accountOwner.name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Company::factory()->count(4)->for($this->team)->create();
    $trashedRecords = Company::factory()->trashed()->count(6)->for($this->team)->create();

    livewire(ListCompanies::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Company::factory(20)->for($this->team)->create();

    // Fetch records with the same sort order as the table (created_at DESC)
    $sortedRecords = Company::query()
        ->whereIn('id', $records->pluck('id'))
        ->orderBy('created_at', 'desc')
        ->get();

    livewire(ListCompanies::class)
        ->assertCanSeeTableRecords($sortedRecords->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($sortedRecords->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Company::factory(5)->for($this->team)->create();

    livewire(ListCompanies::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create a company', function (): void {
    livewire(ListCompanies::class)
        ->callAction('create', data: [
            'name' => 'Acme Corp',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Company::class, [
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);
});

it('can edit a company', function (): void {
    $record = Company::factory()->for($this->team)->create();

    livewire(ListCompanies::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'name' => 'Updated Company',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->name)->toBe('Updated Company');
});

it('can delete a company', function (): void {
    $record = Company::factory()->for($this->team)->create();

    livewire(ListCompanies::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates name is required on create', function (): void {
    livewire(ListCompanies::class)
        ->callAction('create', data: [
            'name' => null,
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ListCompanies::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

it('sets creator_id and team_id via observer when creating a company', function (): void {
    livewire(ListCompanies::class)
        ->callAction('create', data: [
            'name' => 'Observer Test Corp',
        ])
        ->assertHasNoActionErrors();

    $company = Company::query()->where('name', 'Observer Test Corp')->first();

    expect($company->creator_id)->toBe($this->user->id)
        ->and($company->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team company', function (): void {
    $record = Company::factory()->for($this->team)->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team company', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = Company::factory()->for($otherTeam)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Actions\TableLayouts\SaveTablePreference;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\Company;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(PersistsTableState::class, SaveTablePreference::class);

dataset('remembered list pages', [
    'companies' => ListCompanies::class,
    'people' => ListPeople::class,
    'opportunities' => ListOpportunities::class,
    'tasks' => ManageTasks::class,
    'notes' => ManageNotes::class,
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function savedPreferences(): int
{
    return TablePreference::query()->withoutGlobalScopes()->count();
}

function createdAtHiddenAndFirst(array $columns): array
{
    $created = collect($columns)->firstWhere('name', 'created_at');

    return [
        [...$created, 'isToggled' => false],
        ...collect($columns)->reject(fn (array $item): bool => $item['name'] === 'created_at')->values()->all(),
    ];
}

it('brings back hidden columns, order, sort, filters, search and page size on a new visit', function (string $page): void {
    $component = livewire($page);
    $component->call('applyTableColumnManager', createdAtHiddenAndFirst($component->get('tableColumns')), true)
        ->set('tableSort', 'created_at:desc')
        ->set('tableSearch', 'acme')
        ->set('tableRecordsPerPage', 50)
        ->set('tableFilters', ['trashed' => ['value' => 0]]);

    session()->flush();
    $revisit = livewire($page);

    expect($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->get('tableSearch'))->toBe('acme')
        ->and((int) $revisit->get('tableRecordsPerPage'))->toBe(50)
        ->and($revisit->get('tableFilters.trashed.value'))->toEqual(0)
        ->and($revisit->get('tableColumns.0.name'))->toBe('created_at')
        ->and($revisit->get('tableColumns.0.isToggled'))->toBeFalse();
})->with('remembered list pages');

it('writes nothing when a table is opened', function (string $page): void {
    livewire($page);

    expect(savedPreferences())->toBe(0);

    livewire($page)->set('tableSearch', 'acme');
    $updatedAt = TablePreference::query()->withoutGlobalScopes()->value('updated_at');

    $this->travelTo(now()->addHour());
    session()->flush();
    livewire($page);

    expect(TablePreference::query()->withoutGlobalScopes()->value('updated_at'))->toEqual($updatedAt);
})->with('remembered list pages');

it('writes one row per member, workspace and table', function (): void {
    livewire(ListCompanies::class)->set('tableSearch', 'a')->set('tableSearch', 'ab');
    livewire(ListPeople::class)->set('tableSearch', 'a');

    expect(savedPreferences())->toBe(2);
});

it('keeps state apart per workspace', function (): void {
    $first = $this->user->currentWorkspace;
    livewire(ListCompanies::class)->set('tableSort', 'created_at:desc');

    $other = Workspace::factory()->create();
    $this->user->workspaces()->attach($other, ['role' => 'admin']);
    $this->user->unsetRelations();
    Filament::setTenant($other);

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBeNull();

    livewire(ListCompanies::class)->set('tableSort', 'created_at:asc');
    Filament::setTenant($first);
    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBe('created_at:desc')
        ->and(savedPreferences())->toBe(2);
});

it('writes nothing on a follow-up request that changes nothing', function (string $page): void {
    livewire($page)->call('$refresh')->call('gotoPage', 2)->call('$refresh');

    expect(savedPreferences())->toBe(0);
})->with('remembered list pages');

it('writes nothing on follow-up requests when a saved state is open', function (string $page): void {
    $component = livewire($page);
    $component->call('applyTableColumnManager', createdAtHiddenAndFirst($component->get('tableColumns')), true)
        ->set('tableSort', 'created_at:desc')
        ->set('tableSearch', 'acme')
        ->set('tableRecordsPerPage', 50)
        ->set('tableFilters', ['trashed' => ['value' => 0]])
        ->call('updateResizableColumnWidth', 'created_at', '300');
    $updatedAt = TablePreference::query()->withoutGlobalScopes()->value('updated_at');

    $this->travelTo(now()->addHour());
    session()->flush();
    livewire($page)->call('$refresh')->call('gotoPage', 2)->call('$refresh');

    expect(TablePreference::query()->withoutGlobalScopes()->value('updated_at'))->toEqual($updatedAt);
})->with('remembered list pages');

it('lets a query string sort win over saved state and writes nothing', function (): void {
    livewire(ListCompanies::class)->set('tableSort', 'created_at:desc');
    $updatedAt = TablePreference::query()->withoutGlobalScopes()->value('updated_at');

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['sort' => 'name:asc'])->test(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and(TablePreference::query()->withoutGlobalScopes()->value('updated_at'))->toEqual($updatedAt);
});

it('opens at the default page size when the saved one is not offered', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(perPage: 200),
    ]);

    expect((int) livewire(ListCompanies::class)->get('tableRecordsPerPage'))->toBe(25);
});

it('heals a state naming a column the table no longer has', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(
            columns: [['type' => 'column', 'name' => 'custom_fields.gone', 'isToggled' => true]],
            sort: 'custom_fields.gone:asc',
        ),
    ]);

    $component = livewire(ListCompanies::class)->assertOk();
    $names = collect($component->get('tableColumns'))->pluck('name');

    expect($names)->not->toContain('custom_fields.gone')
        ->and($names->all())->toEqual(array_keys($component->instance()->getTable()->getColumns()))
        ->and($component->get('tableSort'))->toBeNull();
});

it('opens a table whose saved columns are missing a name or a visibility', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(columns: [
            ['type' => 'column', 'name' => 'created_at', 'isToggled' => false],
            ['type' => 'column', 'isToggled' => false],
            ['type' => 'column', 'name' => 'creator.name'],
        ]),
    ]);

    $columns = collect(livewire(ListCompanies::class)->assertOk()->get('tableColumns'))->keyBy('name');

    expect($columns['created_at']['isToggled'])->toBeFalse()
        ->and($columns['creator.name']['isToggled'])->toBeTrue();
});

it('loads a filter saved for a deleted custom field', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: ['custom_fields.gone' => ['value' => 'x']]),
    ]);
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    livewire(ListCompanies::class)->assertOk()->assertCanSeeTableRecords([$company]);
});

it('stops writing table state to the session', function (): void {
    $component = livewire(ListCompanies::class)->set('tableSort', 'created_at:desc');
    $component->call('applyTableColumnManager', createdAtHiddenAndFirst($component->get('tableColumns')));

    expect(session()->has($component->instance()->getTableSortSessionKey()))->toBeFalse()
        ->and(session()->has($component->instance()->getTableColumnsSessionKey()))->toBeFalse()
        ->and(session()->get('filament.tables'))->toBeNull();
});

it('brings back a width on a new visit', function (string $page): void {
    livewire($page)->call('updateResizableColumnWidth', 'created_at', '300');
    session()->flush();

    expect(livewire($page)->instance()->getResizableColumnWidths())->toBe(['created_at' => 300]);
})->with('remembered list pages');

it('saves a width in the same request that resizes it', function (): void {
    livewire(ListCompanies::class)->call('updateResizableColumnWidth', 'created_at', '300');

    expect(TablePreference::query()->withoutGlobalScopes()->first()->state->widths)->toBe(['created_at' => 300]);
});

it('drops a saved width for a column the table no longer has', function (): void {
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(widths: ['gone' => 300, 'created_at' => 250]),
    ]);

    expect(livewire(ListCompanies::class)->instance()->getResizableColumnWidths())->toBe(['created_at' => 250]);
});

<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\FilterCondition;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleCompiler;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Queries\Filters\TreeFilter;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(
    ConditionsFilter::class,
    FilterCondition::class,
    FilterFieldCatalog::class,
    FilterRuleCompiler::class,
    HasCustomFieldColumns::class,
    TreeFilter::class,
);

dataset('filter bar pages', [
    'companies' => [ListCompanies::class, Company::class],
    'people' => [ListPeople::class, People::class],
    'opportunities' => [ListOpportunities::class, Opportunity::class],
    'tasks' => [ManageTasks::class, Task::class],
    'notes' => [ManageNotes::class, Note::class],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();
});

it('draws the filter row and no filament filter form', function (string $page): void {
    $html = livewire($page)->html();

    expect($html)
        ->toContain('data-filter-bar')
        ->toContain('fi-filter-bar-start')
        ->toContain('fi-filter-bar-end')
        ->not->toContain('fi-ta-filters-dropdown')
        ->not->toContain('class="fi-ta-filters"');
})->with('filter bar pages');

it('keeps the table filterable while its form stays hidden', function (string $page): void {
    expect(livewire($page)->instance()->getTable()->isFilterable())->toBeTrue();
})->with('filter bar pages');

it('still runs a filter whose form is never drawn', function (string $page, string $model): void {
    $web = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    $api = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);

    livewire($page)
        ->assertCanSeeTableRecords([$web, $api])
        ->set('tableFilters.creation_source.values', [CreationSource::API->value])
        ->assertCanSeeTableRecords([$api])
        ->assertCanNotSeeTableRecords([$web])
        ->assertSet('tableFilters.creation_source.values', [CreationSource::API->value]);
})->with('filter bar pages');

it('narrows the list from the filters key in the url', function (string $page, string $model): void {
    $web = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    $api = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);

    Livewire::withQueryParams(['filters' => ['creation_source' => ['values' => [CreationSource::API->value]]]])
        ->test($page)
        ->assertCanSeeTableRecords([$api])
        ->assertCanNotSeeTableRecords([$web]);
})->with('filter bar pages');

it('renders a page whose url carries garbage filters', function (string $page, string $model, string $query): void {
    $this->get($page::getUrl(['tenant' => $this->workspace]).'?'.$query)->assertSuccessful();
})->with('filter bar pages')->with([
    'trashed scalar' => 'filters[trashed]=x',
    'whole value a string' => 'filters=x',
    'conditions scalar' => 'filters[conditions]=x',
    'creation source scalar' => 'filters[creation_source]=x',
    'creation source nested junk' => 'filters[creation_source][values]=x',
]);

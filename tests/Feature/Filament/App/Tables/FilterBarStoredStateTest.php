<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleCompiler;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\TreeFilter;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

mutates(
    ConditionsFilter::class,
    FilterCondition::class,
    FilterFieldCatalog::class,
    FilterRuleCompiler::class,
    FilterRules::class,
    HasCustomFieldColumns::class,
    HasFilterBar::class,
    LogicFilter::class,
    PersistsTableState::class,
    SortList::class,
    TreeFilter::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    openOpportunitiesOnTheList($this->user, $this->workspace);
});

function filterBarLegacyCase(string $case, User $user): array
{
    $rule = fn (string $field, string $condition, array $value): array => ['field' => $field, 'condition' => $condition, 'value' => $value];

    switch ($case) {
        case 'creation source':
            filterBarScenario('sources', $user);

            return [ListCompanies::class, CrmEntity::Company, ['creation_source' => ['values' => ['api']]], [$rule('creation_source', 'is_any_of', ['api'])], ['Called']];
        case 'assigned to me':
            filterBarRecord($user, Task::class, 'Mine')->assignees()->attach($user);
            filterBarRecord($user, Task::class, 'Theirs');

            return [ManageTasks::class, CrmEntity::Task, ['assigned_to_me' => ['isActive' => true]], [$rule('assignees', 'is_any_of', ['@me'])], ['Mine']];
        case 'assignees':
            $mate = filterBarMember($user);
            filterBarRecord($user, Task::class, 'Mine')->assignees()->attach($user);
            filterBarRecord($user, Task::class, 'Theirs')->assignees()->attach($mate);

            return [ManageTasks::class, CrmEntity::Task, ['assignees' => ['values' => [$mate->getKey()]]], [$rule('assignees', 'is_any_of', [$mate->getKey()])], ['Theirs']];
        case 'single choice':
            filterBarScenario('stages', $user);
            $first = filterBarOptionId($user, 'opportunity', 'stage', 0);

            return [ListOpportunities::class, CrmEntity::Opportunity, ['custom_fields' => ['stage' => ['values' => [$first]]]], [$rule('custom_fields.stage', 'is_any_of', [$first])], ['First']];
        case 'flat custom field key':
            filterBarScenario('stages', $user);
            $first = filterBarOptionId($user, 'opportunity', 'stage', 0);

            return [ListOpportunities::class, CrmEntity::Opportunity, ['custom_fields.stage' => ['values' => [$first]]], [$rule('custom_fields.stage', 'is_any_of', [$first])], ['First']];
        case 'multi choice':
            filterBarScenario('tags', $user);
            $blue = filterBarOptionId($user, 'company', 'palette', 1);

            return [ListCompanies::class, CrmEntity::Company, ['custom_fields' => ['palette' => ['values' => [$blue]]]], [$rule('custom_fields.palette', 'has_any_of', [$blue])], ['Blue']];
        case 'tags':
            filterBarField($user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
            filterBarRecord($user, Company::class, 'Vip', values: ['labels' => ['vip']]);
            filterBarRecord($user, Company::class, 'Plain');

            return [ListCompanies::class, CrmEntity::Company, ['custom_fields' => ['labels' => ['values' => ['vip']]]], [$rule('custom_fields.labels', 'has_any_of', ['vip'])], ['Vip']];
        default:
            filterBarField($user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
            $acme = filterBarRecord($user, Company::class, 'Acme');
            filterBarRecord($user, Opportunity::class, 'With Acme', values: ['partner' => [$acme->getKey()]]);
            filterBarRecord($user, Opportunity::class, 'Alone');

            return [ListOpportunities::class, CrmEntity::Opportunity, ['custom_fields' => ['partner' => ['values' => [$acme->getKey()]]]], [$rule('custom_fields.partner', 'has_any_of', [$acme->getKey()])], ['With Acme']];
    }
}

dataset('legacy filters', ['creation source', 'assigned to me', 'assignees', 'single choice', 'flat custom field key', 'multi choice', 'tags', 'record']);

it('opens each filter of the old popover as the rule it becomes', function (string $case, string $how): void {
    [$page, $entity, $legacy, $rules, $names] = filterBarLegacyCase($case, $this->user);

    $component = filterBarOpen($this, $how, $page, $entity, $legacy);
    $shown = $entity === CrmEntity::Task ? filterBarShownTitles($component) : filterBarShownNames($component);

    expect($component->get('tableFilters.conditions.rules'))->toBe($rules)
        ->and($shown)->toBe($names)
        ->and(array_keys($component->get('tableFilters')))->toBe(['conditions', 'trashed']);
})->with('legacy filters')->with(['view', 'url', 'set']);

it('drops an old filter that is switched off, empty or unknown', function (): void {
    $legacy = [
        'assigned_to_me' => ['isActive' => false],
        'assignees' => ['values' => []],
        'creation_source' => ['values' => 'api'],
        'something_gone' => ['values' => ['x']],
        'custom_fields' => ['gone' => ['values' => ['x']]],
        'company' => ['values' => ['x']],
    ];

    $component = livewire(ManageTasks::class)->set('tableFilters', $legacy);

    expect($component->get('tableFilters'))->toBe(['conditions' => ['match' => 'all', 'rules' => []], 'trashed' => ['value' => null]]);
});

it('reaches one canonical state however the keys are ordered, and heals it to itself', function (): void {
    $rules = [['field' => 'name', 'condition' => 'contains', 'value' => 'glo']];
    $ordered = livewire(ListCompanies::class)->set('tableFilters', ['conditions' => ['match' => 'all', 'rules' => $rules], 'trashed' => ['value' => 0]]);
    $reversed = livewire(ListCompanies::class)->set('tableFilters', ['trashed' => ['value' => 0], 'conditions' => ['rules' => [['value' => 'glo', 'condition' => 'contains', 'field' => 'name']], 'match' => 'all']]);

    $healedAgain = livewire(ListCompanies::class)->call('setFilterRules', $ordered->get('tableFilters.conditions.rules'))->set('tableFilters.trashed.value', 0);

    expect(json_encode($reversed->get('tableFilters')))->toBe(json_encode($ordered->get('tableFilters')))
        ->and(json_encode($healedAgain->get('tableFilters')))->toBe(json_encode($ordered->get('tableFilters')));
});

it('stores only the fields of a rule and the value its condition takes', function (): void {
    $rules = [
        ['field' => 'name', 'condition' => 'is_empty', 'value' => 'ignored', 'extra' => 1],
        ['field' => 'created_at', 'condition' => 'this', 'value' => ['unit' => 'weeks', 'amount' => 3, 'extra' => 1], 'id' => 'x'],
    ];

    $component = livewire(ListCompanies::class)->call('setFilterRules', $rules);

    expect($component->get('tableFilters.conditions.rules'))->toBe([
        ['field' => 'name', 'condition' => 'is_empty'],
        ['field' => 'created_at', 'condition' => 'this', 'value' => ['unit' => 'weeks']],
    ]);
});

it('stores only the valid rules a client sends and clears every chip on request', function (): void {
    $good = ['field' => 'name', 'condition' => 'contains', 'value' => 'glo'];
    filterBarScenario('companies', $this->user);

    $component = livewire(ListCompanies::class)
        ->call('setFilterRules', [['field' => 'nope', 'condition' => 'is_empty'], $good])
        ->set('tableFilters.trashed.value', 0);

    expect($component->get('tableFilters.conditions.rules'))->toBe([$good])
        ->and(filterBarShownNames($component))->toBe([]);

    $component->call('clearFilterBar');

    expect($component->get('tableFilters'))->toBe(['conditions' => ['match' => 'all', 'rules' => []], 'trashed' => ['value' => null]])
        ->and(filterBarShownNames($component))->toBe(['Acme Robotics', 'Globex', 'Initech']);
});

dataset('stored filter shapes', [
    'legacy keys only' => [['creation_source' => ['values' => ['api']]]],
    'the old popover filled with its defaults' => [['assigned_to_me' => ['isActive' => false], 'assignees' => ['values' => []], 'creation_source' => ['values' => ['api']], 'trashed' => ['value' => null], 'custom_fields' => []]],
    'a stale rule next to a good one' => [filterBarRulesState([['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['bogus']], ['field' => 'name', 'condition' => 'contains', 'value' => 'a']])],
    'keys in another order' => [['trashed' => ['value' => null], 'conditions' => ['rules' => [['value' => 'a', 'condition' => 'contains', 'field' => 'name']], 'match' => 'all']]],
]);

function filterBarStateWith(mixed $component, array $filters): TableStateData
{
    $page = $component->instance();
    $state = (new ReflectionMethod($page, 'currentTableState'))->invoke($page)->withoutWidths();
    $state->filters = $filters;

    return $state;
}

it('writes nothing to a view however its filters were stored, on the next request and on a fresh mount', function (array $filters, string $how): void {
    filterBarScenario('companies', $this->user);
    $fresh = livewire(ListCompanies::class);
    $stored = filterBarStateWith($fresh, $filters);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $stored]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    if ($how === 'stored') {
        TablePreference::factory()->for($this->user)->for($this->workspace)->create([
            'entity' => CrmEntity::Company,
            'active_view_id' => $view->getKey(),
            'applied_view_hash' => $stored->withoutWidths()->hash(),
            'state' => filterBarStateWith($fresh, [...$filters, 'custom_fields.gone' => ['values' => ['x']]]),
        ]);
    }

    $component = $how === 'apply'
        ? livewire(ListCompanies::class)->call('applySavedView', $view->getKey())
        : livewire(ListCompanies::class);

    if ($how === 'stored') {
        expect($component->get('activeSavedViewId'))->toBe($view->getKey());
    }

    $component->call('$refresh');

    expect($view->refresh()->updated_at)->toEqual($updatedAt);
})->with('stored filter shapes')->with(['apply', 'stored']);

it('leaves a view alone and shows the stored copy, whether the copy differs from it or only its old filter shape does', function (string $source): void {
    $fresh = livewire(ListCompanies::class);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => filterBarStateWith($fresh, ['creation_source' => ['values' => ['api']]]),
    ]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());
    TablePreference::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'active_view_id' => $view->getKey(),
        'applied_view_hash' => $view->state->withoutWidths()->hash(),
        'state' => filterBarStateWith($fresh, ['creation_source' => ['values' => [$source]], 'custom_fields.gone' => ['values' => ['x']]]),
    ]);

    $component = livewire(ListCompanies::class)->call('$refresh');

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->get('tableFilters.conditions.rules.0.value'))->toBe([$source])
        ->and($view->refresh()->updated_at)->toEqual($updatedAt);
})->with([
    'the copy equals the view' => ['api'],
    'the copy differs' => ['import'],
]);

it('leaves a view alone and shows the stored copy when a stored preference predates the conditions filter', function (bool $changed): void {
    filterBarScenario('companies', $this->user);
    $before = new TableStateData(
        columns: [['type' => 'column', 'name' => 'created_at', 'isToggled' => true]],
        sort: 'created_at:desc',
        filters: ['assigned_to_me' => ['isActive' => false], 'creation_source' => ['values' => ['api']], 'trashed' => ['value' => null]],
    );
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $before]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());
    TablePreference::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'active_view_id' => $view->getKey(),
        'applied_view_hash' => $before->withoutWidths()->hash(),
        'state' => $changed ? new TableStateData(columns: $before->columns, sort: 'created_at:asc', filters: $before->filters) : $before,
    ]);

    $component = livewire(ListCompanies::class);
    $component->call('$refresh');

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->get('tableSort'))->toBe($changed ? 'created_at:asc' : 'created_at:desc')
        ->and($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($component->get('tableFilters.conditions.rules'))->toBe([['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['api']]]);
})->with([
    'a clean copy' => [false],
    'a changed copy' => [true],
]);

it('keeps a relative period unchanged when a day passes, and moves its records', function (): void {
    $this->travelTo('2026-03-18 12:00:00');
    filterBarRecord($this->user, Company::class, 'Far', ['created_at' => '2026-04-20 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Near', ['created_at' => '2026-04-01 10:00:00']);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: filterBarRulesState([['field' => 'created_at', 'condition' => 'in_the_next', 'value' => ['amount' => 30, 'unit' => 'days']]])),
    ]);
    $updatedAt = $view->updated_at;
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());
    $before = filterBarShownNames($component);

    $this->travelTo('2026-03-28 12:00:00');
    $component->call('$refresh');

    expect($before)->toBe(['Near'])
        ->and($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and(filterBarShownNames($component))->toBe(['Far', 'Near']);
});

it('stores a rule added to a view on the view', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => new TableStateData(filters: filterBarRulesState([]))]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());
    expect($view->refresh()->state->filters['conditions']['rules'])->toBe([]);

    $component->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'a']]);

    expect($view->refresh()->state->filters['conditions']['rules'][0])->toMatchArray(['field' => 'name', 'condition' => 'contains', 'value' => 'a']);
});

it('resolves the records of a field with one query however many rules name them', function (): void {
    $companies = [filterBarRecord($this->user, Company::class, 'One'), filterBarRecord($this->user, Company::class, 'Two'), filterBarRecord($this->user, Company::class, 'Three')];
    $component = livewire(ListPeople::class);
    $rules = array_map(fn (Company $company): array => ['field' => 'company', 'condition' => 'is_any_of', 'value' => [$company->getKey()]], $companies);

    DB::enableQueryLog();
    $component->set('tableFilters.conditions.rules', $rules);
    $lookups = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with((string) $query['query'], 'select "id" from "companies"'));
    DB::disableQueryLog();

    expect($lookups)->toHaveCount(1)->and($component->get('tableFilters.conditions.rules'))->toHaveCount(3);
});

it('leaves the empty list description off a list that a rule or the deleted records view empties', function (string $page, string $description): void {
    livewire($page)->assertSee(__($description));

    livewire($page)
        ->set('tableFilters.conditions.rules', [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['import']]])
        ->assertCountTableRecords(0)
        ->assertDontSee(__($description));

    livewire($page)
        ->set('tableFilters.trashed.value', 0)
        ->assertCountTableRecords(0)
        ->assertDontSee(__($description));
})->with([
    'companies' => [ListCompanies::class, 'filament/resources/company.empty_state.description'],
    'people' => [ListPeople::class, 'filament/resources/person.empty_state.description'],
    'opportunities' => [ListOpportunities::class, 'filament/resources/opportunity.empty_state.description'],
    'tasks' => [ManageTasks::class, 'filament/resources/task.empty_state.description'],
    'notes' => [ManageNotes::class, 'filament/resources/note.empty_state.description'],
]);

it('holds exactly the conditions and trashed filters on the five lists', function (string $page): void {
    expect(array_keys(livewire($page)->instance()->getTable()->getFilters()))->toBe(['conditions', 'trashed']);
})->with('filter bar pages');

it('keeps the creation source filter on the notes cards', function (): void {
    openCards()->assertTableFilterExists('creation_source');
});

it('treats the deleted notes view on the cards as filtered', function (): void {
    $cards = openCards()
        ->filterTable('trashed', false)
        ->assertCountTableRecords(0)
        ->assertDontSee(__('filament/resources/note.empty_state.description'));

    expect($cards->instance()->getTable()->getFilter('trashed')->getIndicators())->not->toBeEmpty();
});

it('reads a deleted state as one value however it is written, and stores that value on the view', function (mixed $written, ?int $stored): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: ['trashed' => ['value' => 1]]),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableFilters.trashed.value', $written);

    expect($component->get('tableFilters.trashed.value'))->toBe($stored)
        ->and($view->refresh()->state->filters['trashed']['value'])->toBe($stored);
})->with([
    'true' => [true, 1],
    'the string one' => ['1', 1],
    'the word true' => ['true', 1],
    'false' => [false, 0],
    'the string zero' => ['0', 0],
    'junk' => ['x', null],
    'an array' => [['x'], null],
]);

it('hides deleted records for a junk deleted state and shows only them for the only state', function (): void {
    filterBarRecord($this->user, Company::class, 'Live');
    filterBarRecord($this->user, Company::class, 'Gone')->delete();

    $junk = livewire(ListCompanies::class)->set('tableFilters.trashed.value', 'x');
    $only = livewire(ListCompanies::class)->set('tableFilters.trashed.value', '0');
    $with = livewire(ListCompanies::class)->set('tableFilters.trashed.value', 'true');

    expect(filterBarShownNames($junk))->toBe(['Live'])
        ->and(filterBarShownNames($only))->toBe(['Gone'])
        ->and(filterBarShownNames($with))->toBe(['Gone', 'Live']);
});

it('keeps a text value that filament would read as a boolean or null', function (string $word, string $how): void {
    filterBarRecord($this->user, Company::class, "The {$word} company");
    filterBarRecord($this->user, Company::class, 'Other');
    $rules = [['field' => 'name', 'condition' => 'contains', 'value' => $word]];
    $columns = [['type' => 'column', 'name' => 'created_at', 'isToggled' => true]];
    $state = new TableStateData(columns: $columns, filters: filterBarRulesState($rules));

    if ($how === 'default') {
        $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $state]);
        TablePreference::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'view_order' => [$view->getKey()]]);
    }

    if ($how === 'own table') {
        TablePreference::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $state]);
    }

    $component = $how === 'url'
        ? Livewire::withQueryParams(['filters' => filterBarRulesState($rules)])->test(ListCompanies::class)
        : livewire(ListCompanies::class);

    expect($component->get('tableFilters.conditions.rules'))->toBe($rules)
        ->and(filterBarShownNames($component))->toBe(["The {$word} company"]);
})->with(['true', 'false', 'null'])->with(['default', 'own table', 'url']);

it('lists live records by default, both with deleted included, and deleted alone with only', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);

    livewire($page)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$deleted])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertSet('tableFilters.trashed.value', 1)
        ->assertCanSeeTableRecords([$live, $deleted])
        ->call('setDeletedRecordsFilter', 'only')
        ->assertSet('tableFilters.trashed.value', 0)
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$live])
        ->call('setDeletedRecordsFilter', null)
        ->assertSet('tableFilters.trashed.value', null)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$deleted]);
})->with('filter bar pages');

it('treats any other deleted mode as the default', function (string $page, string $model, mixed $mode): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);

    livewire($page)
        ->call('setDeletedRecordsFilter', 'included')
        ->call('setDeletedRecordsFilter', $mode)
        ->assertSet('tableFilters.trashed.value', null)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$deleted]);
})->with('filter bar pages')->with(['junk', '', '1', 'Included', 'true']);

it('keeps the rules when the deleted mode changes and the deleted mode when the rules change', function (): void {
    $rules = [['field' => 'name', 'condition' => 'contains', 'value' => 'acme']];

    livewire(ListCompanies::class)
        ->call('setFilterRules', $rules)
        ->call('setDeletedRecordsFilter', 'only')
        ->assertSet('tableFilters.conditions.rules', $rules)
        ->call('setFilterRules', [])
        ->assertSet('tableFilters.trashed.value', 0);
});

it('returns to the default when the filter bar is cleared', function (string $page, string $model, ?string $mode): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);

    livewire($page)
        ->call('setFilterRules', [['field' => CrmEntity::tryFromModel($live)->titleColumn(), 'condition' => 'contains', 'value' => 'acme']])
        ->call('setDeletedRecordsFilter', $mode)
        ->call('clearFilterBar')
        ->assertSet('tableFilters', ['conditions' => ['match' => 'all', 'rules' => []], 'trashed' => ['value' => null]])
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$deleted]);
})->with('filter bar pages')->with(['included', 'only']);

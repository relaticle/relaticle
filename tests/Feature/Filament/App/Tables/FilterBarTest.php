<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Enums\WorkspaceRole;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\NoteResource\Pages\NotesCards;
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
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\EntityFilters;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\Filters\TreeFilter;
use App\Queries\FilterTree;
use App\Support\IconPath;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Tests\Helpers\WorkspaceCustomField;

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
        ->not->toContain('fi-ta-filters-dropdown')
        ->not->toContain('class="fi-ta-filters"');
})->with('filter bar pages');

it('draws no search field and is not searchable', function (string $page): void {
    $component = livewire($page);

    expect($component->html())->not->toContain('fi-ta-search-field')
        ->and($component->instance()->getTable()->isSearchable())->toBeFalse();
})->with('filter bar pages');

it('ignores a search that arrives in the URL', function (string $page, string $model): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    Livewire::withQueryParams(['search' => 'zzqx-no-match'])->test($page)
        ->assertSet('tableSearch', '')
        ->assertCanSeeTableRecords([$record]);
})->with('filter bar pages');

it('keeps the table filterable while its form stays hidden', function (string $page): void {
    expect(livewire($page)->instance()->getTable()->isFilterable())->toBeTrue();
})->with('filter bar pages');

it('still runs a filter whose form is never drawn', function (string $page, string $model): void {
    $web = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    $api = $model::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);

    $rules = [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => [CreationSource::API->value]]];

    livewire($page)
        ->assertCanSeeTableRecords([$web, $api])
        ->set('tableFilters.conditions.rules', $rules)
        ->assertCanSeeTableRecords([$api])
        ->assertCanNotSeeTableRecords([$web])
        ->assertSet('tableFilters.conditions.rules', $rules);
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

function filterBarField(User $user, string $entity, string $code, string $type, array $options = [], ?string $lookup = null): void
{
    resolve(CreateCustomField::class)->execute($user, array_filter([
        'entity_type' => $entity,
        'name' => ucfirst($code),
        'code' => $code,
        'type' => $type,
        'options' => $options === [] ? null : array_map(static fn (string $name): array => ['name' => $name], $options),
        'lookup_type' => $lookup,
    ]));
}

function filterBarRecord(User $user, string $model, string $name, array $attributes = [], array $values = []): Model
{
    $column = $model === Task::class || $model === Note::class ? 'title' : 'name';
    $record = $model::factory()->recycle([$user, $user->currentWorkspace])->create([$column => $name, ...$attributes]);
    $entity = CrmEntity::tryFromModel($record)->value;

    foreach ($values as $code => $value) {
        $record->saveCustomFieldValue(WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), $entity, $code), $value);
    }

    return $record;
}

function filterBarOptionId(User $user, string $entity, string $code, int $index): string
{
    return (string) WorkspaceCustomField::byCode($user->currentWorkspace->getKey(), $entity, $code)->options->sortBy('sort_order')->values()[$index]->getKey();
}

function filterBarScenario(string $scenario, User $user): array
{
    $record = fn (string $model, string $name, array $attributes = [], array $values = []): Model => filterBarRecord($user, $model, $name, $attributes, $values);

    return match ($scenario) {
        'companies' => [ListCompanies::class, 'companies', ['Acme Robotics', 'Globex', 'Initech'], array_map(fn (string $name): Model => $record(Company::class, $name), ['Acme Robotics', 'Globex', 'Initech'])],
        'amounts' => [ListOpportunities::class, 'opportunities', ['Small', 'Mid', 'Big', 'None'], [
            $record(Opportunity::class, 'Small', values: ['amount' => 100]),
            $record(Opportunity::class, 'Mid', values: ['amount' => 1000]),
            $record(Opportunity::class, 'Big', values: ['amount' => 5000]),
            $record(Opportunity::class, 'None'),
        ]],
        'stages' => [ListOpportunities::class, 'opportunities', ['First', 'Second', 'Unstaged'], [
            $record(Opportunity::class, 'First', values: ['stage' => filterBarOptionId($user, 'opportunity', 'stage', 0)]),
            $record(Opportunity::class, 'Second', values: ['stage' => filterBarOptionId($user, 'opportunity', 'stage', 1)]),
            $record(Opportunity::class, 'Unstaged'),
        ]],
        'sources' => [ListCompanies::class, 'companies', ['Typed', 'Called'], [
            $record(Company::class, 'Typed', ['creation_source' => CreationSource::WEB]),
            $record(Company::class, 'Called', ['creation_source' => CreationSource::API]),
        ]],
        'tags' => [ListCompanies::class, 'companies', ['Red', 'Blue', 'Plain'], (function () use ($user, $record): array {
            filterBarField($user, 'company', 'palette', CustomFieldType::MULTI_SELECT->value, ['Red', 'Blue']);

            return [
                $record(Company::class, 'Red', values: ['palette' => [filterBarOptionId($user, 'company', 'palette', 0)]]),
                $record(Company::class, 'Blue', values: ['palette' => [filterBarOptionId($user, 'company', 'palette', 1)]]),
                $record(Company::class, 'Plain'),
            ];
        })()],
        'merge' => [ListCompanies::class, 'companies', ['Acme Corp', 'Acme Robotics', 'Corp Holdings'], array_map(fn (string $name): Model => $record(Company::class, $name), ['Acme Corp', 'Acme Robotics', 'Corp Holdings'])],
        'seats' => [ListOpportunities::class, 'opportunities', ['Few', 'Many', 'Unset'], (function () use ($user, $record): array {
            filterBarField($user, 'opportunity', 'seats', CustomFieldType::NUMBER->value);

            return [
                $record(Opportunity::class, 'Few', values: ['seats' => 10]),
                $record(Opportunity::class, 'Many', values: ['seats' => 5000]),
                $record(Opportunity::class, 'Unset'),
            ];
        })()],
        'phones' => [ListPeople::class, 'people', ['Cell', 'Desk', 'Silent'], (function () use ($user, $record): array {
            filterBarField($user, 'people', 'mobile', CustomFieldType::PHONE->value);

            return [
                $record(People::class, 'Cell', values: ['mobile' => ['+14155550100']]),
                $record(People::class, 'Desk', values: ['mobile' => ['+14155550199']]),
                $record(People::class, 'Silent'),
            ];
        })()],
        'icp' => [ListCompanies::class, 'companies', ['Marked', 'Unmarked', 'Never'], [
            $record(Company::class, 'Marked', values: ['icp' => true]),
            $record(Company::class, 'Unmarked', values: ['icp' => false]),
            $record(Company::class, 'Never'),
        ]],
        'emails' => [ListPeople::class, 'people', ['Ann', 'Bob', 'Cy'], [
            $record(People::class, 'Ann', values: ['emails' => ['ann@acme.com']]),
            $record(People::class, 'Bob', values: ['emails' => ['bob@globex.com', 'robert@acme.org']]),
            $record(People::class, 'Cy'),
        ]],
        'jobs' => [ListPeople::class, 'people', ['Chief', 'Nobody'], [
            $record(People::class, 'Chief', values: ['job_title' => 'CTO']),
            $record(People::class, 'Nobody'),
        ]],
        'stale' => [ListOpportunities::class, 'opportunities', ['Fresh', 'Old'], (function () use ($record): array {
            $this->travelTo(now()->subDays(40));
            $old = $record(Opportunity::class, 'Old');
            $this->travelBack();

            return [$record(Opportunity::class, 'Fresh'), $old];
        })->call(test())],
    };
}

function filterBarRules(array $rules, User $user, string $entity): array
{
    return array_map(static function (array $rule) use ($user, $entity): array {
        $rule['value'] = is_array($rule['value'] ?? null)
            ? array_map(static function (mixed $value) use ($rule, $user, $entity): mixed {
                if (! is_string($value) || ! preg_match('/^option:(\d+)$/', $value, $match)) {
                    return $value;
                }

                return filterBarOptionId($user, $entity, str_replace('custom_fields.', '', $rule['field']), (int) $match[1]);
            }, $rule['value'])
            : ($rule['value'] ?? null);

        return $rule;
    }, $rules);
}

function filterBarApiIds(mixed $test, string $path, array $tree): array
{
    return collect($test->getJson("/api/v1/{$path}?".http_build_query(['filter' => $tree]))->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

dataset('compiled conditions', [
    'name contains' => ['companies', [['field' => 'name', 'condition' => 'contains', 'value' => 'ACME']], ['Acme Robotics']],
    'name does not contain' => ['companies', [['field' => 'name', 'condition' => 'does_not_contain', 'value' => 'acme']], ['Globex', 'Initech']],
    'name starts with' => ['companies', [['field' => 'name', 'condition' => 'starts_with', 'value' => 'glo']], ['Globex']],
    'name ends with' => ['companies', [['field' => 'name', 'condition' => 'ends_with', 'value' => 'tics']], ['Acme Robotics']],
    'name is' => ['companies', [['field' => 'name', 'condition' => 'is', 'value' => 'Globex']], ['Globex']],
    'name is not' => ['companies', [['field' => 'name', 'condition' => 'is_not', 'value' => 'Globex']], ['Acme Robotics', 'Initech']],
    'text is empty' => ['jobs', [['field' => 'custom_fields.job_title', 'condition' => 'is_empty']], ['Nobody']],
    'text is not empty' => ['jobs', [['field' => 'custom_fields.job_title', 'condition' => 'is_not_empty']], ['Chief']],
    'text field is' => ['jobs', [['field' => 'custom_fields.job_title', 'condition' => 'is', 'value' => 'CTO']], ['Chief']],
    'number is' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'is', 'value' => '1000']], ['Mid']],
    'number is not' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'is_not', 'value' => 1000]], ['Small', 'Big', 'None']],
    'number greater than' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'greater_than', 'value' => 1000]], ['Big']],
    'number greater or equal' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'greater_or_equal', 'value' => 1000]], ['Mid', 'Big']],
    'number less than' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'less_than', 'value' => 1000]], ['Small']],
    'number less or equal' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'less_or_equal', 'value' => 1000]], ['Small', 'Mid']],
    'number is empty' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'is_empty']], ['None']],
    'number is not empty' => ['amounts', [['field' => 'custom_fields.amount', 'condition' => 'is_not_empty']], ['Small', 'Mid', 'Big']],
    'two rules on one number field' => ['amounts', [
        ['field' => 'custom_fields.amount', 'condition' => 'greater_than', 'value' => 500],
        ['field' => 'custom_fields.amount', 'condition' => 'less_than', 'value' => 2000],
    ], ['Mid']],
    'choice is any of' => ['stages', [['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => ['option:0']]], ['First']],
    'choice is none of keeps unset' => ['stages', [['field' => 'custom_fields.stage', 'condition' => 'is_none_of', 'value' => ['option:0']]], ['Second', 'Unstaged']],
    'choice is empty' => ['stages', [['field' => 'custom_fields.stage', 'condition' => 'is_empty']], ['Unstaged']],
    'choice is not empty' => ['stages', [['field' => 'custom_fields.stage', 'condition' => 'is_not_empty']], ['First', 'Second']],
    'creation source is any of' => ['sources', [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['api']]], ['Called']],
    'creation source is none of' => ['sources', [['field' => 'creation_source', 'condition' => 'is_none_of', 'value' => ['api']]], ['Typed']],
    'multi choice has any of' => ['tags', [['field' => 'custom_fields.palette', 'condition' => 'has_any_of', 'value' => ['option:1']]], ['Blue']],
    'multi choice has none of keeps unset' => ['tags', [['field' => 'custom_fields.palette', 'condition' => 'has_none_of', 'value' => ['option:1']]], ['Red', 'Plain']],
    'multi choice is empty' => ['tags', [['field' => 'custom_fields.palette', 'condition' => 'is_empty']], ['Plain']],
    'checkbox is checked' => ['icp', [['field' => 'custom_fields.icp', 'condition' => 'is_checked']], ['Marked']],
    'checkbox is not checked keeps never stored' => ['icp', [['field' => 'custom_fields.icp', 'condition' => 'is_not_checked']], ['Unmarked', 'Never']],
    'multi text contains' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'contains', 'value' => 'acme']], ['Ann', 'Bob']],
    'multi text does not contain' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'does_not_contain', 'value' => 'acme.org']], ['Ann', 'Cy']],
    'multi text starts with' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'starts_with', 'value' => 'robert']], ['Bob']],
    'multi text ends with' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'ends_with', 'value' => '@acme.com']], ['Ann']],
    'multi text is' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'is', 'value' => 'ann@acme.com']], ['Ann']],
    'multi text is not' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'is_not', 'value' => 'ann@acme.com']], ['Bob', 'Cy']],
    'multi text is empty' => ['emails', [['field' => 'custom_fields.emails', 'condition' => 'is_empty']], ['Cy']],
    'name with a trailing space' => ['companies', [['field' => 'name', 'condition' => 'is', 'value' => 'Globex ']], ['Globex']],
    'two rules on one text field' => ['merge', [
        ['field' => 'name', 'condition' => 'contains', 'value' => 'Acme'],
        ['field' => 'name', 'condition' => 'contains', 'value' => 'Corp'],
    ], ['Acme Corp']],
    'integer field with exponent' => ['seats', [['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => '1e3']], ['Many']],
    'integer field with a decimal point' => ['seats', [['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => '1000.0']], ['Many']],
    'integer field with spaces' => ['seats', [['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => ' 12 ']], ['Many']],
    'phone is' => ['phones', [['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => '+14155550100']], ['Cell']],
    'phone is not' => ['phones', [['field' => 'custom_fields.mobile', 'condition' => 'is_not', 'value' => '+14155550100']], ['Desk', 'Silent']],
    'inactivity' => ['stale', [['field' => 'stale_days', 'condition' => 'no_activity_for', 'value' => 30]], ['Old']],
]);

it('compiles every condition to the records the api lists for the same tree', function (string $scenario, array $rules, array $expected): void {
    [$page, $path, $names, $records] = filterBarScenario($scenario, $this->user);
    $entity = CrmEntity::tryFromModel($records[0]);
    $rules = filterBarRules($rules, $this->user, $entity->value);
    $titles = array_combine(array_map(fn (Model $record): string => (string) $record->getKey(), $records), $names);
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($titles[$record->getKey()], $expected, true))->map->getKey()->sort()->values()->all();
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for($entity, $this->user), $this->user, 'UTC');

    $shown = livewire($page)->set('tableFilters.conditions.rules', $rules)->instance()->getTableRecords()->pluck('id')->sort()->values()->all();

    Sanctum::actingAs($this->user);

    expect($shown)->toBe($expectedIds)->and(filterBarApiIds($this, $path, $tree))->toBe($expectedIds);
})->with('compiled conditions');

it('shows every record for a rule that compiles to nothing', function (string $scenario, array $rule): void {
    [$page, , , $records] = filterBarScenario($scenario, $this->user);

    livewire($page)
        ->set('tableFilters.conditions.rules', [$rule])
        ->assertCanSeeTableRecords($records)
        ->assertCountTableRecords(count($records));
})->with([
    'no value' => ['companies', ['field' => 'name', 'condition' => 'contains']],
    'empty value' => ['companies', ['field' => 'name', 'condition' => 'contains', 'value' => '']],
    'blank value' => ['companies', ['field' => 'name', 'condition' => 'contains', 'value' => '   ']],
    'unknown field' => ['companies', ['field' => 'nope', 'condition' => 'is_empty']],
    'unknown condition' => ['companies', ['field' => 'name', 'condition' => 'nope']],
    'condition the field does not offer' => ['companies', ['field' => 'name', 'condition' => 'is_checked']],
    'not a rule' => ['companies', ['field' => ['x'], 'condition' => ['y']]],
    'fraction on an integer field' => ['seats', ['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => '1.5']],
    'text on an integer field' => ['seats', ['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => 'many']],
    'phone without a country code' => ['phones', ['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => '555 0100']],
    'phone is not without a country code' => ['phones', ['field' => 'custom_fields.mobile', 'condition' => 'is_not', 'value' => '555 0100']],
    'inactivity of zero' => ['stale', ['field' => 'stale_days', 'condition' => 'no_activity_for', 'value' => 0]],
    'inactivity of a negative' => ['stale', ['field' => 'stale_days', 'condition' => 'no_activity_for', 'value' => -3]],
    'inactivity with a fraction' => ['stale', ['field' => 'stale_days', 'condition' => 'no_activity_for', 'value' => 30.5]],
    'inactivity past the maximum' => ['stale', ['field' => 'stale_days', 'condition' => 'no_activity_for', 'value' => StaleDaysFilter::MAX_DAYS + 1]],
]);

it('lists every native filter in definition order before the custom fields', function (string $page, string $model): void {
    $entity = CrmEntity::tryFromModel(new $model);
    $keys = array_keys(FilterFieldCatalog::for($entity, $this->user));
    $native = array_values(array_diff(array_keys(EntityFilters::definitions($entity)), ['assigned_to_me']));

    expect(array_slice($keys, 0, count($native)))->toBe($native)
        ->and(array_slice($keys, count($native)))->each->toStartWith('custom_fields.');
})->with('filter bar pages');

const TEXT_CONDITIONS = ['contains', 'does_not_contain', 'starts_with', 'ends_with', 'is', 'is_not', 'is_empty', 'is_not_empty'];
const NUMBER_CONDITIONS = ['is', 'is_not', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'is_empty', 'is_not_empty'];
const DATE_CONDITIONS = ['is', 'before', 'at_or_before', 'after', 'at_or_after', 'in_the_past', 'in_the_next', 'this', 'is_empty', 'is_not_empty'];
const CHOICE_CONDITIONS = ['is_any_of', 'is_none_of', 'is_empty', 'is_not_empty'];
const MULTI_CHOICE_CONDITIONS = ['has_any_of', 'has_none_of', 'is_empty', 'is_not_empty'];
const CHECKBOX_CONDITIONS = ['is_checked', 'is_not_checked'];

function offeredConditions(?object $field): ?array
{
    return $field === null ? null : array_map(static fn (FilterCondition $condition): string => $condition->value, $field->conditions());
}

it('offers each custom field type exactly its conditions', function (string $type, ?array $expected): void {
    $options = CustomFieldType::from($type)->isChoice() && $type !== CustomFieldType::TAGS_INPUT->value ? ['One', 'Two'] : [];
    filterBarField($this->user, 'company', 'probe', $type, $options, $type === CustomFieldType::RECORD->value ? 'company' : null);

    $catalog = livewire(ListCompanies::class)->instance()->filterFieldCatalog();

    expect(offeredConditions($catalog['custom_fields.probe'] ?? null))->toBe($expected);
})->with([
    'text' => ['text', TEXT_CONDITIONS],
    'number' => ['number', NUMBER_CONDITIONS],
    'currency' => ['currency', NUMBER_CONDITIONS],
    'date' => ['date', DATE_CONDITIONS],
    'date-time' => ['date-time', DATE_CONDITIONS],
    'select' => ['select', CHOICE_CONDITIONS],
    'radio' => ['radio', CHOICE_CONDITIONS],
    'toggle-buttons' => ['toggle-buttons', CHOICE_CONDITIONS],
    'multi-select' => ['multi-select', MULTI_CHOICE_CONDITIONS],
    'checkbox-list' => ['checkbox-list', MULTI_CHOICE_CONDITIONS],
    'tags-input' => ['tags-input', MULTI_CHOICE_CONDITIONS],
    'checkbox' => ['checkbox', CHECKBOX_CONDITIONS],
    'toggle' => ['toggle', CHECKBOX_CONDITIONS],
    'email' => ['email', TEXT_CONDITIONS],
    'phone' => ['phone', TEXT_CONDITIONS],
    'link' => ['link', TEXT_CONDITIONS],
    'record' => ['record', MULTI_CHOICE_CONDITIONS],
    'textarea' => ['textarea', null],
    'rich-editor' => ['rich-editor', null],
    'color-picker' => ['color-picker', null],
]);

it('offers the default domain field the conditions of a text field', function (): void {
    $catalog = livewire(ListCompanies::class)->instance()->filterFieldCatalog();

    expect(offeredConditions($catalog['custom_fields.domains']))->toBe(TEXT_CONDITIONS);
});

it('offers each native field exactly its conditions', function (string $page, string $key, array $expected): void {
    expect(offeredConditions(livewire($page)->instance()->filterFieldCatalog()[$key] ?? null))->toBe($expected);
})->with([
    'created at' => [ListCompanies::class, 'created_at', DATE_CONDITIONS],
    'creation source' => [ListCompanies::class, 'creation_source', CHOICE_CONDITIONS],
    'creator' => [ListPeople::class, 'creator', CHOICE_CONDITIONS],
    'company' => [ListPeople::class, 'company', CHOICE_CONDITIONS],
    'name' => [ListCompanies::class, 'name', TEXT_CONDITIONS],
    'inactivity' => [ListOpportunities::class, 'stale_days', ['no_activity_for']],
]);

it('keeps assigned to me out of the catalog', function (): void {
    expect(livewire(ManageTasks::class)->instance()->filterFieldCatalog())->not->toHaveKey('assigned_to_me');
});

it('labels a native field with the header the member sees', function (string $page): void {
    $component = livewire($page);
    $matched = 0;

    foreach ($component->instance()->filterFieldCatalog() as $key => $field) {
        $column = FilterFieldCatalog::columnFor($key);

        if ($component->instance()->getTable()->getColumn($column) === null) {
            continue;
        }

        $matched++;
        $component->assertSeeHtml('<span class="truncate">'.e($field->label).'</span>');
    }

    expect($matched)->toBeGreaterThanOrEqual(3);
})->with('filter bar pages');

it('reuses the lang labels of the company columns', function (): void {
    $catalog = livewire(ListCompanies::class)->instance()->filterFieldCatalog();

    expect($catalog['creator']->label)->toBe(__('filament/resources/company.fields.created_by.label'))
        ->and($catalog['accountOwner']->label)->toBe(__('filament/resources/company.fields.account_owner.label'))
        ->and($catalog['created_at']->label)->toBe(__('filament/resources/company.fields.created_at.label'));
});

it('follows the tenant when the workspace changes', function (): void {
    filterBarField($this->user, 'company', 'first_only', CustomFieldType::SELECT->value, ['One']);
    $second = Workspace::factory()->create(['personal_workspace' => false, 'user_id' => $this->user->id]);
    $this->user->ownedWorkspaces()->save($second);
    $component = livewire(ListCompanies::class);
    $before = array_keys($component->instance()->filterFieldCatalog());

    $this->user->switchWorkspace($second);
    Filament::setTenant($second);
    filterBarField($this->user->refresh(), 'company', 'second_only', CustomFieldType::SELECT->value, ['One']);
    $after = array_keys(livewire(ListCompanies::class)->instance()->filterFieldCatalog());

    expect($before)->toContain('custom_fields.first_only')->not->toContain('custom_fields.second_only')
        ->and($after)->toContain('custom_fields.second_only')->not->toContain('custom_fields.first_only');
});

it('gives every condition a label and every kind at least one condition', function (): void {
    foreach (FilterCondition::cases() as $condition) {
        expect($condition->label())->not->toStartWith('filament/panel');
    }

    foreach (FilterFieldKind::cases() as $kind) {
        expect($kind->conditions())->not->toBeEmpty();
    }
});

function filterBarCreatedAtRecords(User $user): array
{
    $stamps = [
        'Dec31' => '2025-12-31 23:30:00',
        'Jan01' => '2026-01-01 00:30:00',
        'Feb15' => '2026-02-15 10:00:00',
        'Feb16' => '2026-02-16 00:30:00',
        'Mar01' => '2026-03-01 10:00:00',
        'Mar15' => '2026-03-15 10:00:00',
        'Mar16' => '2026-03-16 00:30:00',
        'Mar18' => '2026-03-18 18:00:00',
        'Mar19' => '2026-03-19 00:30:00',
        'Mar22' => '2026-03-22 23:30:00',
        'Mar23' => '2026-03-23 00:30:00',
        'Mar31' => '2026-03-31 23:30:00',
        'Apr01' => '2026-04-01 00:30:00',
        'Apr17' => '2026-04-17 10:00:00',
        'Apr18' => '2026-04-18 00:30:00',
    ];

    return array_map(fn (string $stamp, string $name): Model => filterBarRecord($user, Company::class, $name, ['created_at' => $stamp]), $stamps, array_keys($stamps));
}

function filterBarShownNames(mixed $component): array
{
    return $component->instance()->getTableRecords()->pluck('name')->sort()->values()->all();
}

dataset('created at conditions', [
    'is a day' => ['is', '2026-03-18', ['Mar18']],
    'is today token' => ['is', '@today', ['Mar18']],
    'before' => ['before', '2026-03-16', ['Dec31', 'Jan01', 'Feb15', 'Feb16', 'Mar01', 'Mar15']],
    'at or before' => ['at_or_before', '2026-03-16', ['Dec31', 'Jan01', 'Feb15', 'Feb16', 'Mar01', 'Mar15', 'Mar16']],
    'after' => ['after', '2026-03-22', ['Mar23', 'Mar31', 'Apr01', 'Apr17', 'Apr18']],
    'at or after' => ['at_or_after', '2026-03-22', ['Mar22', 'Mar23', 'Mar31', 'Apr01', 'Apr17', 'Apr18']],
    'in the past 30 days' => ['in_the_past', ['amount' => 30, 'unit' => 'days'], ['Feb16', 'Mar01', 'Mar15', 'Mar16', 'Mar18']],
    'in the past 29 days' => ['in_the_past', ['amount' => 29, 'unit' => 'days'], ['Mar01', 'Mar15', 'Mar16', 'Mar18']],
    'in the past 2 weeks' => ['in_the_past', ['amount' => 2, 'unit' => 'weeks'], ['Mar15', 'Mar16', 'Mar18']],
    'in the past 1 month' => ['in_the_past', ['amount' => 1, 'unit' => 'months'], ['Mar01', 'Mar15', 'Mar16', 'Mar18']],
    'in the past 1 quarter' => ['in_the_past', ['amount' => 1, 'unit' => 'quarters'], ['Dec31', 'Jan01', 'Feb15', 'Feb16', 'Mar01', 'Mar15', 'Mar16', 'Mar18']],
    'in the next 30 days' => ['in_the_next', ['amount' => 30, 'unit' => 'days'], ['Mar18', 'Mar19', 'Mar22', 'Mar23', 'Mar31', 'Apr01', 'Apr17']],
    'in the next 1 week' => ['in_the_next', ['amount' => 1, 'unit' => 'weeks'], ['Mar18', 'Mar19', 'Mar22', 'Mar23']],
    'this week' => ['this', ['unit' => 'weeks'], ['Mar16', 'Mar18', 'Mar19', 'Mar22']],
    'this month' => ['this', ['unit' => 'months'], ['Mar01', 'Mar15', 'Mar16', 'Mar18', 'Mar19', 'Mar22', 'Mar23', 'Mar31']],
    'this quarter' => ['this', ['unit' => 'quarters'], ['Jan01', 'Feb15', 'Feb16', 'Mar01', 'Mar15', 'Mar16', 'Mar18', 'Mar19', 'Mar22', 'Mar23', 'Mar31']],
    'this year' => ['this', ['unit' => 'years'], ['Jan01', 'Feb15', 'Feb16', 'Mar01', 'Mar15', 'Mar16', 'Mar18', 'Mar19', 'Mar22', 'Mar23', 'Mar31', 'Apr01', 'Apr17', 'Apr18']],
    'this day' => ['this', ['unit' => 'days'], ['Mar18']],
]);

it('compiles each date condition against a fixed clock, as the api reads the same tree', function (string $condition, mixed $value, array $expected): void {
    $this->travelTo('2026-03-18 12:00:00');
    $records = filterBarCreatedAtRecords($this->user);
    $rules = [['field' => 'created_at', 'condition' => $condition, 'value' => $value]];
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Company, $this->user), $this->user, 'UTC');
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($record->name, $expected, true))->map->getKey()->sort()->values()->all();

    $shown = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($shown))->toBe(collect($expected)->sort()->values()->all())
        ->and(filterBarApiIds($this, 'companies', $tree))->toBe($expectedIds);
})->with('created at conditions');

it('puts this week on the monday six days before a sunday', function (): void {
    $this->travelTo('2026-03-22 12:00:00');
    filterBarCreatedAtRecords($this->user);

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'this', 'value' => ['unit' => 'weeks']]]);

    expect(filterBarShownNames($component))->toBe(['Mar16', 'Mar18', 'Mar19', 'Mar22']);
});

it('reads today in the viewer zone and not in utc', function (): void {
    $this->user->update(['timezone' => 'Pacific/Auckland']);
    $this->travelTo('2026-03-18 12:00:00');
    filterBarRecord($this->user, Company::class, 'Evening in Auckland', ['created_at' => '2026-03-18 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Morning in Auckland', ['created_at' => '2026-03-18 20:00:00']);

    $today = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'is', 'value' => '@today']]);

    expect(filterBarShownNames($today))->toBe(['Morning in Auckland']);
});

it('counts a period from the viewer day and not the utc day', function (): void {
    $this->user->update(['timezone' => 'Pacific/Auckland']);
    $this->travelTo('2026-03-18 12:00:00');
    filterBarField($this->user, 'company', 'signed_at', CustomFieldType::DATE_TIME->value);
    foreach (['Evening' => '2026-03-18 10:00:00', 'Morning' => '2026-03-18 20:00:00'] as $name => $stamp) {
        filterBarRecord($this->user, Company::class, $name, ['created_at' => $stamp], ['signed_at' => $stamp]);
    }
    $today = fn (string $field): array => [['field' => $field, 'condition' => 'this', 'value' => ['unit' => 'days']]];

    $native = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $today('created_at'));
    $custom = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $today('custom_fields.signed_at'));

    expect(filterBarShownNames($native))->toBe(['Morning'])
        ->and(filterBarShownNames($custom))->toBe(['Morning']);
});

it('shows every record for a period above the maximum of its unit', function (int $amount, string $unit): void {
    $this->travelTo('2026-03-18 12:00:00');
    $records = filterBarCreatedAtRecords($this->user);
    filterBarRecord($this->user, Opportunity::class, 'Soon', values: ['close_date' => '2026-03-25']);
    $value = ['amount' => $amount, 'unit' => $unit];

    $native = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'in_the_past', 'value' => $value]]);
    $custom = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', [['field' => 'custom_fields.close_date', 'condition' => 'in_the_past', 'value' => $value]]);

    expect($native->get('tableFilters.conditions.rules'))->toBe([])
        ->and($native->instance()->getTableRecords())->toHaveCount(count($records))
        ->and($custom->instance()->getTableRecords())->toHaveCount(1);
})->with([
    'years' => [101, 'years'],
    'quarters' => [41, 'quarters'],
    'months' => [121, 'months'],
    'weeks' => [521, 'weeks'],
    'days' => [3651, 'days'],
]);

it('still filters a period at the maximum of its unit', function (int $amount, string $unit): void {
    $this->travelTo('2026-03-18 12:00:00');
    filterBarRecord($this->user, Company::class, 'Recent', ['created_at' => '2026-01-10 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Ancient', ['created_at' => '1900-01-10 10:00:00']);
    $value = ['amount' => $amount, 'unit' => $unit];

    $past = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'in_the_past', 'value' => $value]]);
    $next = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'in_the_next', 'value' => $value]]);

    expect(filterBarShownNames($past))->toBe(['Recent'])
        ->and($past->get('tableFilters.conditions.rules.0.value.amount'))->toBe($amount)
        ->and(filterBarShownNames($next))->toBe([]);
})->with([
    'years' => [100, 'years'],
    'quarters' => [40, 'quarters'],
    'months' => [120, 'months'],
    'weeks' => [520, 'weeks'],
    'days' => [3650, 'days'],
]);

it('moves a relative period with the clock while the stored rule stays the same', function (): void {
    $this->travelTo('2026-03-18 12:00:00');
    filterBarRecord($this->user, Company::class, 'Far', ['created_at' => '2026-04-20 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Near', ['created_at' => '2026-04-01 10:00:00']);
    $rules = [['field' => 'created_at', 'condition' => 'in_the_next', 'value' => ['amount' => 30, 'unit' => 'days']]];
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: ['conditions' => ['match' => 'all', 'rules' => $rules]]),
    ]);

    $before = filterBarShownNames(livewire(ListCompanies::class)->call('applySavedView', $view->getKey()));
    $this->travelTo('2026-03-28 12:00:00');
    $after = filterBarShownNames(livewire(ListCompanies::class)->call('applySavedView', $view->getKey()));

    expect($before)->toBe(['Near'])->and($after)->toBe(['Far', 'Near'])
        ->and($view->refresh()->state->filters['conditions']['rules'])->toEqual($rules);
});

it('shows every record for a date rule that compiles to nothing', function (mixed $condition, mixed $value): void {
    $this->travelTo('2026-03-18 12:00:00');
    $records = filterBarCreatedAtRecords($this->user);

    livewire(ListCompanies::class)
        ->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => $condition, 'value' => $value]])
        ->assertCountTableRecords(count($records));
})->with([
    'a time of day' => ['is', '2026-03-18T10:00:00Z'],
    'text' => ['before', 'yesterday'],
    'a day that does not exist' => ['after', '2026-02-30'],
    'no value' => ['before', null],
    'a list' => ['is', ['2026-03-18']],
    'no amount' => ['in_the_past', ['unit' => 'days']],
    'a zero amount' => ['in_the_past', ['amount' => 0, 'unit' => 'days']],
    'an amount past the maximum' => ['in_the_next', ['amount' => 3651, 'unit' => 'days']],
    'a fractional amount' => ['in_the_next', ['amount' => 1.5, 'unit' => 'days']],
    'an unknown unit' => ['in_the_past', ['amount' => 3, 'unit' => 'fortnights']],
    'no unit' => ['this', []],
    'a scalar period' => ['in_the_past', '30'],
]);

it('keeps a month end from overflowing when it counts months back', function (): void {
    $this->travelTo('2026-03-31 12:00:00');
    filterBarRecord($this->user, Company::class, 'Feb28', ['created_at' => '2026-02-28 10:00:00']);
    filterBarRecord($this->user, Company::class, 'Feb27', ['created_at' => '2026-02-27 10:00:00']);

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', [['field' => 'created_at', 'condition' => 'in_the_past', 'value' => ['amount' => 1, 'unit' => 'months']]]);

    expect(filterBarShownNames($component))->toBe(['Feb28']);
});

it('filters a date custom field by a day and by a period as the api does', function (): void {
    $this->travelTo('2026-03-18 12:00:00');
    $soon = filterBarRecord($this->user, Opportunity::class, 'Soon', values: ['close_date' => '2026-03-25']);
    $later = filterBarRecord($this->user, Opportunity::class, 'Later', values: ['close_date' => '2026-06-25']);
    $unset = filterBarRecord($this->user, Opportunity::class, 'Unset');
    $compile = fn (array $rules): array => (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Opportunity, $this->user), $this->user, 'UTC');
    $next = [['field' => 'custom_fields.close_date', 'condition' => 'in_the_next', 'value' => ['amount' => 14, 'unit' => 'days']]];
    $exact = [['field' => 'custom_fields.close_date', 'condition' => 'is', 'value' => '2026-06-25']];

    $nextShown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $next);
    $exactShown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $exact);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($nextShown))->toBe(['Soon'])
        ->and(filterBarApiIds($this, 'opportunities', $compile($next)))->toBe([$soon->getKey()])
        ->and(filterBarShownNames($exactShown))->toBe(['Later'])
        ->and(filterBarApiIds($this, 'opportunities', $compile($exact)))->toBe([$later->getKey()])
        ->and($unset->getKey())->not->toBeIn([$soon->getKey(), $later->getKey()]);
});

function filterBarMember(User $owner): User
{
    $member = User::factory()->create(['current_workspace_id' => $owner->currentWorkspace->getKey()]);
    $owner->currentWorkspace->users()->attach($member, ['role' => 'member']);

    return $member;
}

function filterBarLinkedPeople(User $user): array
{
    $acme = filterBarRecord($user, Company::class, 'Acme');
    $globex = filterBarRecord($user, Company::class, 'Globex');

    return [
        $acme,
        $globex,
        filterBarRecord($user, People::class, 'At Acme', ['company_id' => $acme->getKey()]),
        filterBarRecord($user, People::class, 'At Globex', ['company_id' => $globex->getKey()]),
        filterBarRecord($user, People::class, 'Freelance'),
    ];
}

it('filters a relation by record ids as the api does', function (string $condition, array $expected): void {
    [$acme] = $records = filterBarLinkedPeople($this->user);
    $rules = [['field' => 'company', 'condition' => $condition, 'value' => [$acme->getKey()]]];
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::People, $this->user), $this->user, 'UTC');
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($record->name, $expected, true))->map->getKey()->sort()->values()->all();

    $shown = livewire(ListPeople::class)->set('tableFilters.conditions.rules', $rules);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($shown))->toBe(collect($expected)->sort()->values()->all())
        ->and(filterBarApiIds($this, 'people', $tree))->toBe($expectedIds);
})->with([
    'is any of' => ['is_any_of', ['At Acme']],
    'is none of keeps the unlinked' => ['is_none_of', ['At Globex', 'Freelance']],
]);

it('filters assignees by member ids and by me, as the api does', function (): void {
    $colleague = filterBarMember($this->user);
    $mine = filterBarRecord($this->user, Task::class, 'Mine');
    $theirs = filterBarRecord($this->user, Task::class, 'Theirs');
    $nobodys = filterBarRecord($this->user, Task::class, 'Nobodys');
    $mine->assignees()->attach($this->user);
    $theirs->assignees()->attach($colleague);
    $compile = fn (array $rules): array => (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Task, $this->user), $this->user, 'UTC');
    $me = [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => ['@me']]];
    $notThem = [['field' => 'assignees', 'condition' => 'is_none_of', 'value' => [$colleague->getKey()]]];

    $meShown = livewire(ManageTasks::class)->set('tableFilters.conditions.rules', $me);
    $notThemShown = livewire(ManageTasks::class)->set('tableFilters.conditions.rules', $notThem);

    Sanctum::actingAs($this->user);

    expect($meShown->instance()->getTableRecords()->pluck('title')->all())->toBe(['Mine'])
        ->and($notThemShown->instance()->getTableRecords()->pluck('title')->sort()->values()->all())->toBe(['Mine', 'Nobodys'])
        ->and(filterBarApiIds($this, 'tasks', $compile($me)))->toBe([$mine->getKey()])
        ->and(filterBarApiIds($this, 'tasks', $compile($notThem)))->toBe(collect([$mine, $nobodys])->map->getKey()->sort()->values()->all());
});

it('gives each member their own records when two members apply one shared me rule', function (): void {
    $colleague = filterBarMember($this->user);
    filterBarRecord($this->user, Company::class, 'By owner');
    filterBarRecord($this->user, Company::class, 'By colleague', ['creator_id' => $colleague->getKey()]);
    $rules = [['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']]];

    $owner = filterBarShownNames(livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules));

    $this->actingAs($colleague);
    $other = filterBarShownNames(livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules));

    expect($owner)->toBe(['By owner'])->and($other)->toBe(['By colleague']);
});

it('filters a record custom field by record ids as the api does', function (string $condition, array $expected): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $acme = filterBarRecord($this->user, Company::class, 'Acme');
    $globex = filterBarRecord($this->user, Company::class, 'Globex');
    $records = [
        filterBarRecord($this->user, Opportunity::class, 'With Acme', values: ['partner' => [$acme->getKey()]]),
        filterBarRecord($this->user, Opportunity::class, 'With Globex', values: ['partner' => [$globex->getKey()]]),
        filterBarRecord($this->user, Opportunity::class, 'Alone'),
    ];
    $rules = [['field' => 'custom_fields.partner', 'condition' => $condition, 'value' => [$acme->getKey()]]];
    $tree = (new FilterRuleCompiler)->compile($rules, FilterFieldCatalog::for(CrmEntity::Opportunity, $this->user), $this->user, 'UTC');
    $expectedIds = collect($records)->filter(fn (Model $record): bool => in_array($record->name, $expected, true))->map->getKey()->sort()->values()->all();

    $shown = livewire(ListOpportunities::class)->set('tableFilters.conditions.rules', $rules);

    Sanctum::actingAs($this->user);

    expect(filterBarShownNames($shown))->toBe(collect($expected)->sort()->values()->all())
        ->and(filterBarApiIds($this, 'opportunities', $tree))->toBe($expectedIds);
})->with([
    'has any of' => ['has_any_of', ['With Acme']],
    'has none of keeps the empty' => ['has_none_of', ['With Globex', 'Alone']],
]);

it('treats a record custom field link to a deleted record as no link in the bar', function (string $condition, string $values, array $expected): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $live = filterBarRecord($this->user, Company::class, 'Live');
    $gone = filterBarRecord($this->user, Company::class, 'Gone');
    filterBarRecord($this->user, Opportunity::class, 'Orphaned', values: ['partner' => [$gone->getKey()]]);
    filterBarRecord($this->user, Opportunity::class, 'Mixed', values: ['partner' => [$live->getKey(), $gone->getKey()]]);
    filterBarRecord($this->user, Opportunity::class, 'Alone');
    $gone->delete();
    $ids = ['none' => [], 'live' => [$live->getKey()], 'gone' => [$gone->getKey()], 'gone and live' => [$gone->getKey(), $live->getKey()]][$values];

    $shown = livewire(ListOpportunities::class)->call('setFilterRules', [['field' => 'custom_fields.partner', 'condition' => $condition, 'value' => $ids]]);

    expect(filterBarShownNames($shown))->toBe($expected);
})->with([
    'is empty' => ['is_empty', 'none', ['Alone', 'Orphaned']],
    'is not empty' => ['is_not_empty', 'none', ['Mixed']],
    'has any of a live record' => ['has_any_of', 'live', ['Mixed']],
    'has any of a deleted record' => ['has_any_of', 'gone', []],
    'has none of a deleted record' => ['has_none_of', 'gone', ['Alone', 'Mixed', 'Orphaned']],
    'has none of a deleted and a live record' => ['has_none_of', 'gone and live', ['Alone', 'Orphaned']],
]);

it('shows every record for a link rule whose ids are not usable', function (string $field, mixed $value): void {
    filterBarLinkedPeople($this->user);

    livewire(ListPeople::class)
        ->set('tableFilters.conditions.rules', [['field' => $field, 'condition' => 'is_any_of', 'value' => $value]])
        ->assertCountTableRecords(3);
})->with([
    'a string' => ['company', 'x'],
    'an empty list' => ['company', []],
    'not a record id' => ['company', ['nope']],
    'me on a relation' => ['company', ['@me']],
    'too many ids' => ['company', array_fill(0, 101, '01J8Z4Y6T5Q2M9N3B7K1W0X8VD')],
    'nested lists' => ['company', [['01J8Z4Y6T5Q2M9N3B7K1W0X8VD']]],
]);

it('bounds a relation filter by the workspace the member opened', function (): void {
    $first = filterBarLinkedPeople($this->user);
    $second = Workspace::factory()->create(['personal_workspace' => false, 'user_id' => $this->user->id]);
    $this->user->ownedWorkspaces()->save($second);
    $this->user->switchWorkspace($second);
    Filament::setTenant($second);
    $user = $this->user->refresh();
    $inSecond = Company::factory()->recycle([$user, $second])->create(['name' => 'Second Co']);
    People::factory()->recycle([$user, $second])->create(['name' => 'At Second', 'company_id' => $inSecond->getKey()]);
    People::factory()->recycle([$user, $second])->create(['name' => 'Lone Second']);

    $none = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_none_of', 'value' => [$inSecond->getKey()]]]);
    $foreign = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$first[0]->getKey()]]]);

    $mixed = livewire(ListPeople::class)->set('tableFilters.conditions.rules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$first[0]->getKey(), $inSecond->getKey()]]]);

    expect(filterBarShownNames($none))->toBe(['Lone Second'])
        ->and(filterBarShownNames($foreign))->toBe(['At Second', 'Lone Second'])
        ->and($foreign->get('tableFilters.conditions.rules'))->toBe([])
        ->and($foreign->html())->not->toContain($first[0]->name)
        ->and($mixed->get('tableFilters.conditions.rules.0.value'))->toBe([$inSecond->getKey()])
        ->and(filterBarShownNames($mixed))->toBe(['At Second']);
});

function filterBarForeignCompany(string $name): Company
{
    $stranger = User::factory()->withWorkspace()->create();

    return Company::withoutEvents(fn (): Company => Company::factory()->create([
        'name' => $name,
        'workspace_id' => $stranger->currentWorkspace->getKey(),
        'account_owner_id' => $stranger->getKey(),
    ]));
}

function filterBarSearch(mixed $test, string $page, string $field, string $search = ''): array
{
    return livewire($page)->instance()->searchFilterOptions($field, $search);
}

it('searches the records of a relation field by name inside the workspace', function (): void {
    filterBarRecord($this->user, Company::class, 'Acme Local');
    filterBarRecord($this->user, Company::class, 'Globex');
    filterBarForeignCompany('Acme Foreign');

    $found = filterBarSearch($this, ListPeople::class, 'company', 'acme');

    expect(array_column($found, 'label'))->toBe(['Acme Local'])
        ->and(array_keys($found[0]))->toBe(['id', 'label', 'image', 'icon', 'circular'])
        ->and(array_column(filterBarSearch($this, ListPeople::class, 'company'), 'label'))->toBe(['Acme Local', 'Globex']);
});

it('keeps a lookup inside the user workspace even when the panel tenant is another', function (string $page, string $field): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    $other = Workspace::factory()->create(['personal_workspace' => false]);
    $foreign = Company::withoutEvents(fn (): Company => Company::factory()->create(['name' => 'Zeta Foreign', 'workspace_id' => $other->getKey()]));
    $component = livewire($page);

    Filament::setTenant($other);

    expect($foreign->workspace_id)->toBe($other->getKey())
        ->and($component->instance()->searchFilterOptions($field, 'zeta'))->toBe([]);
})->with([
    'a relation field' => [ListPeople::class, 'company'],
    'a record custom field' => [ListOpportunities::class, 'custom_fields.partner'],
]);

it('searches members of this workspace and no one else', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => 'Casey Colleague']);
    User::factory()->withWorkspace()->create(['name' => 'Casey Stranger']);

    $found = filterBarSearch($this, ListCompanies::class, 'creator', 'casey');

    expect($found)->toBe([[
        'id' => $colleague->getKey(),
        'label' => 'Casey Colleague',
        'image' => $colleague->getFilamentAvatarUrl(),
        'icon' => null,
        'circular' => true,
    ]]);
});

it('leaves the viewer out of a member search because the me entry stands for them', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => "{$this->user->name} Junior"]);

    $found = filterBarSearch($this, ListCompanies::class, 'creator', $this->user->name);

    expect(array_column($found, 'id'))->toBe([$colleague->getKey()])
        ->and(array_column(filterBarSearch($this, ListCompanies::class, 'creator'), 'id'))->not->toContain($this->user->getKey());
});

it('marks a company with its logo or the company icon and a person with the person icon', function (): void {
    $logo = filterBarRecord($this->user, Company::class, 'Acme Logo');
    $logo->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    filterBarRecord($this->user, Company::class, 'Acme Plain');
    filterBarRecord($this->user, People::class, 'Acme Person');
    filterBarField($this->user, 'opportunity', 'contact_pick', CustomFieldType::RECORD->value, lookup: 'people');

    $companies = collect(filterBarSearch($this, ListPeople::class, 'company', 'acme'))->keyBy('label');
    $people = filterBarSearch($this, ListOpportunities::class, 'custom_fields.contact_pick', 'acme');

    expect($companies['Acme Logo'])->toMatchArray(['image' => $logo->fresh()->logo, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($companies['Acme Plain'])->toMatchArray(['image' => null, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($people[0])->toMatchArray(['label' => 'Acme Person', 'image' => null, 'icon' => CrmEntity::People->iconPath(), 'circular' => true]);
});

it('looks up company logos without a query per company', function (): void {
    foreach (range(1, 6) as $number) {
        filterBarRecord($this->user, Company::class, "Logo {$number}")
            ->addMediaFromString(onePixelPng())->usingFileName("logo-{$number}.png")->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    }
    $component = livewire(ListPeople::class);

    DB::enableQueryLog();
    $found = $component->instance()->searchFilterOptions('company', 'logo');
    $queries = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'from "media"'));
    DB::disableQueryLog();

    expect($found)->toHaveCount(6)->and($queries)->toHaveCount(1);
});

it('reads only the columns and the logo collection a company option needs', function (): void {
    filterBarRecord($this->user, Company::class, 'Logo Co')
        ->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $component = livewire(ListPeople::class);

    DB::enableQueryLog();
    $component->instance()->searchFilterOptions('company', 'logo');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->first(fn (string $query): bool => str_contains($query, 'from "companies"')))
        ->toContain('select "companies"."id", "companies"."name", "companies"."deleted_at" from "companies"')
        ->and($queries->first(fn (string $query): bool => str_contains($query, 'from "media"')))
        ->toContain('"collection_name" = ?');
});

it('reads no media for records that carry no logo', function (string $page, string $entity, string $lookup, string $related): void {
    filterBarField($this->user, $entity, 'pick', CustomFieldType::RECORD->value, lookup: $lookup);
    foreach (range(1, 3) as $number) {
        filterBarRecord($this->user, $related, "Logo {$number}");
    }
    $component = livewire($page);

    DB::enableQueryLog();
    $found = $component->instance()->searchFilterOptions('custom_fields.pick', 'logo');
    $media = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], '"media"'));
    DB::disableQueryLog();

    expect($found)->toHaveCount(3)->and($media)->toHaveCount(0);
})->with([
    'people on an opportunity' => [ListOpportunities::class, 'opportunity', 'people', People::class],
    'opportunities on a person' => [ListPeople::class, 'people', 'opportunity', Opportunity::class],
]);

it('searches the lookup records of a record custom field', function (): void {
    filterBarField($this->user, 'opportunity', 'partner', CustomFieldType::RECORD->value, lookup: 'company');
    filterBarRecord($this->user, Company::class, 'Acme Local');
    filterBarForeignCompany('Acme Foreign');

    expect(array_column(filterBarSearch($this, ListOpportunities::class, 'custom_fields.partner', 'acme'), 'label'))->toBe(['Acme Local']);
});

it('caps a search at twenty options', function (): void {
    Company::factory()->count(25)->recycle([$this->user, $this->workspace])->create();

    expect(filterBarSearch($this, ListPeople::class, 'company'))->toHaveCount(20);
});

it('treats a search term as text and not as a pattern', function (string $term, array $expected): void {
    foreach (['A_B', 'AXB', '100%', 'Plain'] as $name) {
        filterBarRecord($this->user, Company::class, $name);
    }

    expect(array_column(filterBarSearch($this, ListPeople::class, 'company', $term), 'label'))->toBe($expected);
})->with([
    'an underscore' => ['A_B', ['A_B']],
    'a percent sign' => ['%', ['100%']],
    'a backslash' => ['\\', []],
    'a null byte' => ["A\0B", []],
]);

it('returns nothing for a field that holds no records', function (string $field): void {
    filterBarRecord($this->user, Company::class, 'Acme');

    expect(filterBarSearch($this, ListPeople::class, $field, 'a'))->toBe([]);
})->with([
    'unknown' => ['nope'],
    'text' => ['name'],
    'date' => ['created_at'],
    'choice' => ['creation_source'],
    'a field of another entity' => ['stale_days'],
    'a custom field prefix alone' => ['custom_fields.'],
]);

it('returns nothing for a viewer the policy keeps out of the related records', function (): void {
    filterBarRecord($this->user, Company::class, 'Acme');
    $component = livewire(ListPeople::class);
    $this->user->forceFill(['email_verified_at' => null])->save();

    expect($component->instance()->searchFilterOptions('company', ''))->toBe([]);
});

it('drops a phone value the shared filter would reject', function (string $value): void {
    [$page, , , $records] = filterBarScenario('phones', $this->user);

    livewire($page)
        ->set('tableFilters.conditions.rules', [['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => $value]])
        ->assertCountTableRecords(count($records));
})->with(['+abc', '+1 (555)', '+', '++1415', '+1415555019x', '+1234567890123456789']);

it('still narrows by a full phone number', function (): void {
    [$page, , , $records] = filterBarScenario('phones', $this->user);

    livewire($page)
        ->set('tableFilters.conditions.rules', [['field' => 'custom_fields.mobile', 'condition' => 'is', 'value' => '+1 415 555 0100']])
        ->assertCanSeeTableRecords([$records[0]])
        ->assertCountTableRecords(1);
});

it('never gives a native field a raw lang key for a label', function (string $page, string $model): void {
    $entity = CrmEntity::tryFromModel(new $model);
    $labelled = livewire($page)->instance()->filterFieldCatalog();

    foreach ([FilterFieldCatalog::for($entity, $this->user), $labelled] as $catalog) {
        foreach ($catalog as $field) {
            expect($field->label)->not->toStartWith('filament/')->not->toBeEmpty();
        }
    }
})->with('filter bar pages');

it('words a fallback label as the column header', function (): void {
    $company = FilterFieldCatalog::for(CrmEntity::Company, $this->user);
    $task = FilterFieldCatalog::for(CrmEntity::Task, $this->user);

    expect($company['name']->label)->toBe('Company')
        ->and($company['created_at']->label)->toBe('Creation Date')
        ->and($company['updated_at']->label)->toBe('Last Update')
        ->and($company['creator']->label)->toBe('Created By')
        ->and($company['accountOwner']->label)->toBe('Account Owner')
        ->and($task['title']->label)->toBe('Title')
        ->and($task['created_at']->label)->toBe('Created At')
        ->and($task['assignees']->label)->toBe('Assignees');
});

function filterBarOpen(mixed $test, string $how, string $page, CrmEntity $entity, array $filters): mixed
{
    return match ($how) {
        'view' => livewire($page)->call('applySavedView', SavedView::factory()->for($test->user)->for($test->workspace)->create([
            'entity' => $entity,
            'state' => new TableStateData(filters: $filters),
        ])->getKey()),
        'url' => Livewire::withQueryParams(['filters' => $filters])->test($page),
        'set' => livewire($page)->set('tableFilters', $filters),
    };
}

function filterBarShownTitles(mixed $component): array
{
    return $component->instance()->getTableRecords()->pluck('title')->sort()->values()->all();
}

function filterBarRulesState(array $rules): array
{
    return ['conditions' => ['match' => 'all', 'rules' => $rules]];
}

dataset('unusable rules', [
    'unknown field' => [['field' => 'nope', 'condition' => 'is_empty']],
    'unknown condition' => [['field' => 'name', 'condition' => 'nope', 'value' => 'x']],
    'condition the field does not offer' => [['field' => 'name', 'condition' => 'is_checked']],
    'value of the wrong shape' => [['field' => 'name', 'condition' => 'contains', 'value' => ['x']]],
    'unknown creation source' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['bogus']]],
    'one hundred and one values' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => array_map(fn (int $i): string => "v{$i}", range(1, 101))]],
    'empty string in a list' => [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['', 'api']]],
    'three thousand characters' => [['field' => 'name', 'condition' => 'contains', 'value' => str_repeat('a', 3000)]],
    'not a rule' => ['x'],
]);

it('drops a rule that cannot apply and keeps the others, from a view, the url and a direct set', function (mixed $unusable, string $how): void {
    filterBarScenario('companies', $this->user);
    $good = ['field' => 'name', 'condition' => 'contains', 'value' => 'glo'];

    $component = filterBarOpen($this, $how, ListCompanies::class, CrmEntity::Company, filterBarRulesState([$unusable, $good]));

    expect(filterBarShownNames($component))->toBe(['Globex'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([$good]);
})->with('unusable rules')->with(['view', 'url', 'set']);

it('drops a text value past the cap on a multi text field and still renders', function (string $how): void {
    filterBarRecord($this->user, People::class, 'Ann', values: ['emails' => ['ann@acme.com']]);
    $long = ['field' => 'custom_fields.emails', 'condition' => 'contains', 'value' => str_repeat('a', 300)];
    $good = ['field' => 'custom_fields.emails', 'condition' => 'contains', 'value' => 'acme'];

    $component = filterBarOpen($this, $how, ListPeople::class, CrmEntity::People, filterBarRulesState([$long, $good]))->assertOk();

    expect($component->get('tableFilters.conditions.rules'))->toBe([$good])
        ->and(filterBarShownNames($component))->toBe(['Ann']);
})->with(['view', 'url', 'set']);

it('drops text that holds a nul byte', function (string $how): void {
    filterBarScenario('companies', $this->user);
    $good = ['field' => 'name', 'condition' => 'contains', 'value' => 'glo'];
    $nul = ['field' => 'name', 'condition' => 'contains', 'value' => "a\0b"];

    $component = filterBarOpen($this, $how, ListCompanies::class, CrmEntity::Company, filterBarRulesState([$nul, $good]));

    expect(filterBarShownNames($component))->toBe(['Globex'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([$good]);
})->with(['url', 'set']);

it('drops a stale option id from a list and the rule when none remain', function (string $how): void {
    [$page] = filterBarScenario('stages', $this->user);
    $first = filterBarOptionId($this->user, 'opportunity', 'stage', 0);
    $stale = '01J8Z4Y6T5Q2M9N3B7K1W0X8VD';
    $rules = [
        ['field' => 'custom_fields.stage', 'condition' => 'is_none_of', 'value' => [$stale]],
        ['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => [$stale, $first]],
    ];

    $component = filterBarOpen($this, $how, $page, CrmEntity::Opportunity, filterBarRulesState($rules));

    expect(filterBarShownNames($component))->toBe(['First'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => [$first]]]);
})->with(['view', 'url', 'set']);

it('drops a member who left the workspace and keeps me', function (string $how): void {
    $stranger = User::factory()->create();
    $mine = filterBarRecord($this->user, Task::class, 'Mine');
    $mine->assignees()->attach($this->user);
    filterBarRecord($this->user, Task::class, 'Nobodys');
    $rules = [['field' => 'assignees', 'condition' => 'is_any_of', 'value' => [$stranger->getKey(), '@me']]];

    $component = filterBarOpen($this, $how, ManageTasks::class, CrmEntity::Task, filterBarRulesState($rules));

    expect(filterBarShownTitles($component))->toBe(['Mine'])
        ->and($component->get('tableFilters.conditions.rules.0.value'))->toBe(['@me']);
})->with(['view', 'url', 'set']);

it('drops a record of another workspace and never prints its name', function (string $how): void {
    $foreign = filterBarForeignCompany('Hostile Holdings');
    filterBarRecord($this->user, People::class, 'Local Lead');
    $rules = [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$foreign->getKey()]]];

    $component = filterBarOpen($this, $how, ListPeople::class, CrmEntity::People, filterBarRulesState($rules));

    expect(filterBarShownNames($component))->toBe(['Local Lead'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe([])
        ->and($component->html())->not->toContain('Hostile Holdings');
})->with(['view', 'url', 'set']);

it('keeps the same rule on a free text tags field without looking its values up', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip']]);
    filterBarRecord($this->user, Company::class, 'Plain');
    $rules = [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['vip']]];

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules);

    expect(filterBarShownNames($component))->toBe(['Vip'])
        ->and($component->get('tableFilters.conditions.rules'))->toBe($rules);
});

it('keeps the first twenty conditions and drops the rest', function (): void {
    filterBarScenario('companies', $this->user);
    $rule = ['field' => 'name', 'condition' => 'contains', 'value' => 'o'];

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', array_fill(0, 500, $rule));

    expect($component->get('tableFilters.conditions.rules'))->toHaveCount(FilterTree::MAX_CONDITIONS)
        ->and(filterBarShownNames($component))->toBe(['Acme Robotics', 'Globex']);
});

it('counts a relative period as two conditions', function (): void {
    $rules = array_map(
        fn (int $amount): array => ['field' => 'created_at', 'condition' => 'in_the_past', 'value' => ['amount' => $amount, 'unit' => 'days']],
        range(1, 11),
    );
    filterBarRecord($this->user, Company::class, 'Today');

    $component = livewire(ListCompanies::class)->set('tableFilters.conditions.rules', $rules);

    expect(array_column(array_column($component->get('tableFilters.conditions.rules'), 'value'), 'amount'))->toBe(range(1, 10))
        ->and(filterBarShownNames($component))->toBe(['Today']);
});

it('renders a page for a hostile query string or direct state', function (string $query): void {
    $this->get(ListCompanies::getUrl(['tenant' => $this->workspace]).'?'.$query)->assertSuccessful();
})->with([
    'conditions a string' => 'filters[conditions]=x',
    'rules a string' => 'filters[conditions][rules]=x',
    'a rule a string' => 'filters[conditions][rules][0]=x',
    'a value as a deep array' => 'filters[conditions][rules][0][field]=name&filters[conditions][rules][0][condition]=contains&filters[conditions][rules][0][value][a][b][c]=x',
]);

it('survives a client that sets a filter to a string', function (string $page): void {
    livewire($page)
        ->set('tableFilters', ['trashed' => 'x', 'conditions' => 'y'])
        ->assertOk()
        ->assertSet('tableFilters.trashed.value', null)
        ->assertSet('tableFilters.conditions.rules', []);
})->with('filter bar pages');

it('survives a client that calls the filter setters with nothing or the wrong type', function (string $page, string $model, string $method, array $arguments, ?int $trashed): void {
    livewire($page)
        ->call('setDeletedRecordsFilter', 'included')
        ->call($method, ...$arguments)
        ->assertOk()
        ->assertSet('tableFilters.trashed.value', $trashed)
        ->assertSet('tableFilters.conditions.rules', []);
})->with('filter bar pages')->with([
    'deleted mode missing' => ['setDeletedRecordsFilter', [], null],
    'deleted mode an array' => ['setDeletedRecordsFilter', [['x']], null],
    'rules missing' => ['setFilterRules', [], 1],
    'rules a string' => ['setFilterRules', ['x'], 1],
]);

it('clears a rule that was set when the client sends rules that are not a list', function (string $page, string $model, array $arguments): void {
    $rule = ['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => ['api']];

    livewire($page)
        ->call('setFilterRules', [$rule])
        ->assertSet('tableFilters.conditions.rules', [$rule])
        ->call('setDeletedRecordsFilter', 'included')
        ->call('setFilterRules', ...$arguments)
        ->assertOk()
        ->assertSet('tableFilters.conditions.rules', [])
        ->assertSet('tableFilters.trashed.value', 1);
})->with('filter bar pages')->with([
    'rules missing' => [[]],
    'rules a string' => [['x']],
]);

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

it('writes healed rules through setFilterRules and clears the bar with clearFilterBar', function (): void {
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

it('reads a view as unchanged however its filters were stored, on the next request and on a fresh mount', function (array $filters, string $how): void {
    filterBarScenario('companies', $this->user);
    $fresh = livewire(ListCompanies::class);
    $stored = filterBarStateWith($fresh, $filters);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $stored]);

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
        expect($component->get('activeSavedViewId'))->toBe($view->getKey())
            ->and($component->instance()->isSavedViewModified())->toBeFalse();
    }

    $component->call('$refresh');

    expect($component->instance()->isSavedViewModified())->toBeFalse();
})->with('stored filter shapes')->with(['apply', 'stored']);

it('reads a copy of a view as unchanged when only its old filter shape differs, and as changed when it really differs', function (string $source, bool $changed): void {
    $fresh = livewire(ListCompanies::class);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => filterBarStateWith($fresh, ['creation_source' => ['values' => ['api']]]),
    ]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'active_view_id' => $view->getKey(),
        'applied_view_hash' => $view->state->withoutWidths()->hash(),
        'state' => filterBarStateWith($fresh, ['creation_source' => ['values' => [$source]], 'custom_fields.gone' => ['values' => ['x']]]),
    ]);

    $component = livewire(ListCompanies::class);

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->instance()->isSavedViewModified())->toBe($changed);
})->with([
    'the copy equals the view' => ['api', false],
    'the copy differs' => ['import', true],
]);

it('reads a view as unchanged when a stored preference predates the conditions filter', function (bool $changed): void {
    filterBarScenario('companies', $this->user);
    $before = new TableStateData(
        columns: [['type' => 'column', 'name' => 'created_at', 'isToggled' => true]],
        sort: 'created_at:desc',
        filters: ['assigned_to_me' => ['isActive' => false], 'creation_source' => ['values' => ['api']], 'trashed' => ['value' => null]],
    );
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => $before]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'active_view_id' => $view->getKey(),
        'applied_view_hash' => $before->withoutWidths()->hash(),
        'state' => $changed ? new TableStateData(columns: $before->columns, sort: 'created_at:asc', filters: $before->filters) : $before,
    ]);

    $component = livewire(ListCompanies::class);
    $component->call('$refresh');

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->instance()->isSavedViewModified())->toBe($changed)
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
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());
    $before = filterBarShownNames($component);

    $this->travelTo('2026-03-28 12:00:00');
    $component->call('$refresh');

    expect($before)->toBe(['Near'])
        ->and($component->instance()->isSavedViewModified())->toBeFalse()
        ->and(filterBarShownNames($component))->toBe(['Far', 'Near']);
});

it('stores a rule added to a view on the view', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'state' => new TableStateData(filters: filterBarRulesState([]))]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());
    expect($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'a']]);

    expect($component->instance()->isSavedViewModified())->toBeFalse()
        ->and($view->refresh()->state->filters['conditions']['rules'][0])->toMatchArray(['field' => 'name', 'condition' => 'contains', 'value' => 'a']);
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
    livewire(NotesCards::class)->assertTableFilterExists('creation_source');
});

it('treats the deleted notes view on the cards as filtered', function (): void {
    $cards = livewire(NotesCards::class)
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
        TablePreference::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company, 'default_view_id' => $view->getKey()]);
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

function filterBarDeletedPair(User $user, string $model, string $name = 'Acme'): array
{
    return [filterBarRecord($user, $model, $name), tap(filterBarRecord($user, $model, $name))->delete()];
}

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

it('reads a saved view that includes deleted records as unchanged and stores the mode when it switches', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: ['trashed' => ['value' => 1]]),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect($component->get('tableFilters.trashed.value'))->toBe(1)
        ->and($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('$refresh');

    expect($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('setDeletedRecordsFilter', 'only');

    expect($view->refresh()->state->filters['trashed']['value'])->toBe(0);

    $component->call('setDeletedRecordsFilter', 'included');

    expect($view->refresh()->state->filters['trashed']['value'])->toBe(1)
        ->and($component->instance()->isSavedViewModified())->toBeFalse();
});

it('lists neither the live nor the deleted record when a negated name rule matches both', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $field = CrmEntity::tryFromModel($live)->titleColumn();

    livewire($page)
        ->call('setDeletedRecordsFilter', 'included')
        ->call('setFilterRules', [['field' => $field, 'condition' => 'does_not_contain', 'value' => 'Acme']])
        ->assertCanNotSeeTableRecords([$live, $deleted]);
})->with('filter bar pages');

it('applies a positive rule to the deleted view', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $other = tap(filterBarRecord($this->user, $model, 'Globex'))->delete();
    $rules = [['field' => CrmEntity::tryFromModel($live)->titleColumn(), 'condition' => 'contains', 'value' => 'Acme']];

    livewire($page)
        ->call('setFilterRules', $rules)
        ->call('setDeletedRecordsFilter', 'only')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$live, $other])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$live, $deleted])
        ->assertCanNotSeeTableRecords([$other]);
})->with('filter bar pages');

it('applies a negated rule to the deleted view and to the live and deleted view', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $liveOther = filterBarRecord($this->user, $model, 'Globex');
    $deletedOther = tap(filterBarRecord($this->user, $model, 'Globex'))->delete();
    $rules = [['field' => CrmEntity::tryFromModel($live)->titleColumn(), 'condition' => 'does_not_contain', 'value' => 'Acme']];

    livewire($page)
        ->call('setFilterRules', $rules)
        ->call('setDeletedRecordsFilter', 'only')
        ->assertCanSeeTableRecords([$deletedOther])
        ->assertCanNotSeeTableRecords([$live, $deleted, $liveOther])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$liveOther, $deletedOther])
        ->assertCanNotSeeTableRecords([$live, $deleted])
        ->call('setDeletedRecordsFilter', null)
        ->assertCanSeeTableRecords([$liveOther])
        ->assertCanNotSeeTableRecords([$live, $deleted, $deletedOther]);
})->with('filter bar pages');

it('keeps a deleted record that is not checked and drops one that is, when deleted records are included', function (): void {
    [, , , $markedLive] = filterBarScenario('icp', $this->user);
    $marked = filterBarRecord($this->user, Company::class, 'Marked gone', values: ['icp' => true]);
    $unmarked = filterBarRecord($this->user, Company::class, 'Unmarked gone', values: ['icp' => false]);
    $never = filterBarRecord($this->user, Company::class, 'Never gone');
    collect([$marked, $unmarked, $never])->each->delete();

    livewire(ListCompanies::class)
        ->call('setFilterRules', [['field' => 'custom_fields.icp', 'condition' => 'is_not_checked']])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$markedLive[1], $markedLive[2], $unmarked, $never])
        ->assertCanNotSeeTableRecords([$markedLive[0], $marked]);
});

it('keeps a deleted record whose number differs and drops one that equals it, when deleted records are included', function (): void {
    $equal = filterBarRecord($this->user, Opportunity::class, 'Equal gone', values: ['amount' => 1000]);
    $other = filterBarRecord($this->user, Opportunity::class, 'Other gone', values: ['amount' => 100]);
    $blank = filterBarRecord($this->user, Opportunity::class, 'Blank gone');
    $liveEqual = filterBarRecord($this->user, Opportunity::class, 'Equal live', values: ['amount' => 1000]);
    $liveOther = filterBarRecord($this->user, Opportunity::class, 'Other live', values: ['amount' => 5000]);
    collect([$equal, $other, $blank])->each->delete();

    livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'custom_fields.amount', 'condition' => 'is_not', 'value' => 1000]])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$other, $blank, $liveOther])
        ->assertCanNotSeeTableRecords([$equal, $liveEqual]);
});

it('hands the chip bar every field with its conditions', function (): void {
    $fields = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect($fields['name'])
        ->toMatchArray(['label' => 'Company', 'kind' => 'text'])
        ->and(array_column($fields['name']['conditions'], 'value'))->toBe(['contains', 'does_not_contain', 'starts_with', 'ends_with', 'is', 'is_not', 'is_empty', 'is_not_empty'])
        ->and($fields['name']['conditions'][0])->toBe(['value' => 'contains', 'label' => 'contains', 'takesValue' => true, 'shape' => 'text'])
        ->and($fields['name']['icon'])->not->toBeEmpty();
});

it('hands the chip bar a choice field options and offers the viewer as the only member option', function (): void {
    filterBarField($this->user, 'opportunity', 'stage_pick', 'select', ['Won', 'Lost']);

    $fields = collect(livewire(ListOpportunities::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect(array_column($fields['custom_fields.stage_pick']['options'], 'label'))->toBe(['Won', 'Lost'])
        ->and(array_column($fields['custom_fields.stage_pick']['options'], 'color'))->toBe([null, null])
        ->and($fields['creator']['options'])->toBe([[
            'id' => '@me',
            'label' => "{$this->user->name} (me)",
            'image' => $this->user->getFilamentAvatarUrl(),
            'icon' => null,
            'circular' => true,
            'color' => null,
        ]])
        ->and($fields['company']['options'])->toBe([]);
});

it('hands the chip bar a tags field known tags as the text a rule stores', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip', 'lead']]);

    $fields = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect(array_column($fields['custom_fields.labels']['options'], 'id'))->toEqualCanonicalizing(['vip', 'lead'])
        ->and(array_column($fields['custom_fields.labels']['options'], 'label'))->toEqualCanonicalizing(['vip', 'lead']);
});

it('hands the chip bar option colours as real colours only when the field shows them', function (): void {
    filterBarField($this->user, 'opportunity', 'stage_pick', 'select', ['Won', 'Lost']);
    $field = WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'opportunity', 'stage_pick');
    $field->options->each(fn ($option) => $option->update(['settings' => new CustomFieldOptionSettingsData(color: '#ff0000')]));
    $colors = fn (): array => array_column(collect(livewire(ListOpportunities::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.stage_pick']['options'], 'color');

    expect($colors())->toBe([null, null]);

    $field->update(['settings' => new CustomFieldSettingsData(enable_option_colors: true)]);
    Once::flush();

    expect($colors())->toBe(['#ff0000', '#ff0000']);
});

it('resolves a named colour of a native option to the colour the theme draws', function (): void {
    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['creation_source']['options'];
    $colors = array_column($options, 'color', 'id');

    expect($colors['web'])->toBe(FilamentColor::getColors()['info'][500])
        ->and($colors['api'])->toBe(Color::Purple[500])
        ->and($colors['chat'])->toBe(Color::Indigo[500])
        ->and(array_filter($colors, is_null(...)))->toBe([]);
});

it('offers every tag once whatever its case, including one whose option is gone', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    $vip = filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['VIP', 'old']]);
    filterBarRecord($this->user, Company::class, 'Vip lower', values: ['labels' => ['vip']]);
    filterBarRecord($this->user, Company::class, 'Plain');
    WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'company', 'labels')->options()->where('name', 'old')->delete();

    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.labels']['options'];

    expect(array_column($options, 'id'))->toEqualCanonicalizing(['VIP', 'old'])
        ->and(livewire(ListCompanies::class)
            ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['old']]])
            ->assertCanSeeTableRecords([$vip])->assertCountTableRecords(1))->not->toBeNull();
});

it('offers no tag that only a deleted record holds', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Live', values: ['labels' => ['kept', 'shared']]);
    filterBarRecord($this->user, Company::class, 'Gone', values: ['labels' => ['dropped', 'shared']])->delete();
    WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'company', 'labels')->options()->delete();

    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.labels']['options'];

    expect(array_column($options, 'id'))->toEqualCanonicalizing(['kept', 'shared']);
});

it('narrows the list by a tag whatever the case it was stored in', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    $upper = filterBarRecord($this->user, Company::class, 'Upper', values: ['labels' => ['VIP']]);
    $lower = filterBarRecord($this->user, Company::class, 'Lower', values: ['labels' => ['vip']]);
    $other = filterBarRecord($this->user, Company::class, 'Other', values: ['labels' => ['lead']]);

    livewire(ListCompanies::class)
        ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['VIP']]])
        ->assertCanSeeTableRecords([$upper, $lower])
        ->assertCanNotSeeTableRecords([$other])
        ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_none_of', 'value' => ['vip']]])
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$upper, $lower]);
});

it('hands the chip bar a label for every stored id, naming a deleted record', function (): void {
    $companies = collect(['Aa', 'Bb', 'Cc'])->map(fn (string $name): Model => filterBarRecord($this->user, Company::class, $name));
    $companies->last()->delete();

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'company', 'condition' => 'is_any_of', 'value' => $companies->map->getKey()->all()],
            ['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me', (string) $this->user->getKey()]],
            ['field' => 'name', 'condition' => 'contains', 'value' => 'x'],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown[0]['labels'], 'label'))->toBe(['Aa', 'Bb', 'Cc (deleted)'])
        ->and(array_column($shown[0]['labels'], 'id'))->toBe($companies->map(fn (Model $company): string => (string) $company->getKey())->all())
        ->and(array_column($shown[1]['labels'], 'label'))->toBe(["{$this->user->name} (me)"])
        ->and($shown[1]['display'])->toBe("{$this->user->name} (me)")
        ->and($shown[1]['labels'][0]['image'])->toBe($this->user->getFilamentAvatarUrl())
        ->and($shown[0]['labels'][0])->toMatchArray(['image' => null, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($shown[2]['labels'])->toBe([]);
});

it('carries the mark of every stored record in the rule labels and gives a deleted company the icon', function (): void {
    $logo = filterBarRecord($this->user, Company::class, 'Logo Co');
    $logo->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $gone = filterBarRecord($this->user, Company::class, 'Gone Co');
    $gone->addMediaFromString(onePixelPng())->usingFileName('gone.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $gone->delete();
    $colleague = filterBarMember($this->user);

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'company', 'condition' => 'is_any_of', 'value' => [$logo->getKey(), $gone->getKey()]],
            ['field' => 'creator', 'condition' => 'is_any_of', 'value' => [$colleague->getKey()]],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect($shown[0]['labels'][0])->toMatchArray(['image' => $logo->fresh()->logo, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($shown[0]['labels'][1])->toMatchArray(['label' => 'Gone Co (deleted)', 'image' => null, 'icon' => CrmEntity::Company->iconPath()])
        ->and($shown[1]['labels'][0])->toMatchArray(['image' => $colleague->getFilamentAvatarUrl(), 'icon' => null, 'circular' => true]);
});

it('reads a rule as the chip shows it', function (): void {
    $rules = [
        ['field' => 'name', 'condition' => 'contains', 'value' => 'acme'],
        ['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']],
        ['field' => 'name', 'condition' => 'is_empty'],
    ];

    $shown = livewire(ListCompanies::class)->call('setFilterRules', $rules)->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['acme', "{$this->user->name} (me)", '']);
});

it('reads Me as the name of whoever opens a shared view', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => 'Casey Colleague']);
    $rules = [['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']]];
    $display = fn (): string => livewire(ListCompanies::class)->call('setFilterRules', $rules)->instance()->getFilterBarData()['rules'][0]['display'];

    expect($display())->toBe("{$this->user->name} (me)");

    $this->actingAs($colleague);

    expect($display())->toBe('Casey Colleague (me)');
});

it('shows the viewer once, as me, whichever way a rule stores them', function (): void {
    expect(livewire(ListCompanies::class)->instance()->getFilterBarData()['viewerId'])->toBe((string) $this->user->getKey());

    $viewer = (string) $this->user->getKey();
    $rules = fn (array $value): array => [['field' => 'creator', 'condition' => 'is_any_of', 'value' => $value]];
    $shown = fn (array $value): array => livewire(ListCompanies::class)->call('setFilterRules', $rules($value))->instance()->getFilterBarData()['rules'][0];

    expect($shown(['@me', $viewer]))->toMatchArray(['display' => "{$this->user->name} (me)"])
        ->and(array_column($shown(['@me', $viewer])['labels'], 'id'))->toBe(['@me'])
        ->and($shown([$viewer])['display'])->toBe("{$this->user->name} (me)")
        ->and($shown([$viewer])['labels'][0])->toMatchArray(['id' => $viewer, 'label' => "{$this->user->name} (me)", 'image' => $this->user->getFilamentAvatarUrl()]);
});

it('names a deleted company in a rule instead of showing a blank or an id', function (): void {
    $live = filterBarRecord($this->user, Company::class, 'Initech');
    $gone = filterBarRecord($this->user, Company::class, 'Globex');
    $gone->delete();

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$gone->getKey(), $live->getKey()]]])
        ->instance()->getFilterBarData()['rules'];

    expect($shown)->toHaveCount(1)
        ->and($shown[0]['display'])->toBe('Globex (deleted), Initech');
});

it('summarises a long list of values with a count of the rest', function (): void {
    $companies = collect(['Aa', 'Bb', 'Cc', 'Dd'])->map(fn (string $name): Model => filterBarRecord($this->user, Company::class, $name));

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => $companies->map->getKey()->all()]])
        ->instance()->getFilterBarData()['rules'];

    expect($shown[0]['display'])->toBe('Aa, Bb +2');
});

it('writes a currency value with its symbol and decimals and a plain number with grouping, and keeps the stored value raw', function (): void {
    resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Budget',
        'code' => 'budget',
        'type' => 'currency',
        'settings' => ['currency_code' => 'USD', 'display_type' => 'symbol', 'decimal_places' => 0],
    ]);
    filterBarField($this->user, 'opportunity', 'seats', 'number');
    resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Fee',
        'code' => 'fee',
        'type' => 'currency',
        'settings' => ['currency_code' => 'EUR', 'display_type' => 'symbol', 'decimal_places' => 2],
    ]);

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'custom_fields.budget', 'condition' => 'greater_than', 'value' => 20000],
            ['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => 10000],
            ['field' => 'custom_fields.fee', 'condition' => 'less_than', 'value' => 1234.5],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['$20,000', '10,000', '€1,234.50'])
        ->and(array_column($shown, 'value'))->toBe([20000, 10000, 1234.5]);
});

it('writes the relative period of a date rule in words', function (): void {
    filterBarField($this->user, 'opportunity', 'closes', 'date');

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'custom_fields.closes', 'condition' => 'in_the_next', 'value' => ['amount' => 30, 'unit' => 'days']],
            ['field' => 'custom_fields.closes', 'condition' => 'this', 'value' => ['unit' => 'months']],
            ['field' => 'custom_fields.closes', 'condition' => 'is', 'value' => '@today'],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['30 days', 'month', 'today']);
});

it('tells the empty list which rules hide its records and clears them from a button', function (string $page, string $model, string $records): void {
    $model::factory()->recycle([$this->user, $this->workspace])->create();
    $column = $model === Task::class || $model === Note::class ? 'title' : 'name';

    livewire($page)
        ->assertDontSee("No {$records} match this view")
        ->call('setFilterRules', [['field' => $column, 'condition' => 'contains', 'value' => 'zzqx-no-match']])
        ->assertSee("No {$records} match this view")
        ->assertSee('Clear filters')
        ->callAction(TestAction::make('clearFilterBar')->table())
        ->assertDontSee("No {$records} match this view")
        ->assertSet('tableFilters.conditions.rules', []);
})->with([
    'companies' => [ListCompanies::class, Company::class, 'companies'],
    'people' => [ListPeople::class, People::class, 'people'],
    'opportunities' => [ListOpportunities::class, Opportunity::class, 'opportunities'],
    'tasks' => [ManageTasks::class, Task::class, 'tasks'],
    'notes' => [ManageNotes::class, Note::class, 'notes'],
]);

it('reads a saved number rule as unchanged when the number comes back as text', function (int|float $saved, string $written): void {
    filterBarScenario('amounts', $this->user);
    $rule = fn (mixed $value): array => ['match' => 'all', 'rules' => [['field' => 'custom_fields.amount', 'condition' => 'greater_than', 'value' => $value]]];
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'state' => new TableStateData(filters: ['conditions' => $rule($saved)]),
    ]);

    $component = livewire(ListOpportunities::class)->call('applySavedView', $view->getKey());

    expect($component->instance()->isSavedViewModified())->toBeFalse();

    $component->set('tableFilters.conditions', $rule($written));

    expect($component->instance()->isSavedViewModified())->toBeFalse()
        ->and($component->get('tableFilters.conditions.rules.0.value'))->toBe($saved);
})->with([
    'whole' => [1000, '1000'],
    'decimal' => [1000.5, '1000.5'],
    'negative' => [-5, '-5'],
]);

it('sets the sort from the filter bar the way a header click does', function (string $page): void {
    $component = livewire($page);
    $column = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => $column->isSortable())->getName();

    $component->call('setFilterBarSorts', [['column' => $column, 'direction' => 'desc']])
        ->assertSet('tableSort', "{$column}:desc");

    expect($component->instance()->getFilterBarData()['sorts'])->toHaveCount(1)
        ->and($component->instance()->getFilterBarData()['sorts'][0])->toMatchArray(['column' => $column, 'direction' => 'desc']);

    $component->call('setFilterBarSorts', [['column' => $column, 'direction' => 'asc']])->assertSet('tableSort', "{$column}:asc")
        ->call('setFilterBarSorts', [])->assertSet('tableSort', null)->assertSet('tableThenSorts', null);

    expect($component->instance()->getFilterBarData()['sorts'])->toBe([]);
})->with('filter bar pages');

it('ignores a sort the table does not hold or cannot sort', function (string $page): void {
    $component = livewire($page);
    $plain = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => ! $column->isSortable());
    $sortable = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => $column->isSortable())->getName();

    $rejected = array_map(fn (string $name): array => ['column' => $name, 'direction' => 'desc'], array_filter(['no_such_column', 'id', '', $plain?->getName()]));

    $component->call('setFilterBarSorts', [...$rejected, ['column' => $sortable, 'direction' => 'sideways'], ['column' => $sortable], ['column' => $sortable, 'direction' => 'desc'], 'name', null])
        ->assertSet('tableSort', "{$sortable}:asc")
        ->assertSet('tableThenSorts', null);
})->with('filter bar pages');

it('offers the sort control only columns the member can see and sort', function (): void {
    $component = livewire(ListCompanies::class);
    $sortable = array_column($component->instance()->getFilterBarData()['sortable'], 'key');

    expect($sortable)->toContain('name')->and($sortable)->not->toContain('no_such_column');

    $component->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']]);
    $component->call('sortTable', 'no_such_column', 'asc');

    expect($component->instance()->getFilterBarData()['sorts'])->toBe([]);
});

it('lists the groups a table declares and writes the grouping a toolbar change writes', function (): void {
    $component = livewire(ManageTasks::class);
    $data = $component->instance()->getFilterBarData();

    expect(array_column($data['groups'], 'id'))->toBe(['status_group', 'priority_group'])
        ->and($data['grouping'])->toBeNull();

    $component->call('setFilterBarGrouping', 'priority_group', 'desc')->assertSet('tableGrouping', 'priority_group:desc');

    expect($component->instance()->getFilterBarData()['grouping'])->toMatchArray(['id' => 'priority_group', 'direction' => 'desc']);

    $component->call('setFilterBarGrouping', 'nonsense', 'asc')->assertSet('tableGrouping', 'priority_group:desc')
        ->call('setFilterBarGrouping', 'status_group', 'sideways')->assertSet('tableGrouping', 'priority_group:desc')
        ->call('setFilterBarGrouping', null, null)->assertSet('tableGrouping', null);
});

it('declares no groups on the lists that group nothing', function (string $page): void {
    expect(livewire($page)->instance()->getFilterBarData()['groups'])->toBe([]);
})->with([
    'companies' => [ListCompanies::class],
    'people' => [ListPeople::class],
    'opportunities' => [ListOpportunities::class],
    'notes' => [ManageNotes::class],
]);

it('draws no grouping settings of its own on any list', function (string $page): void {
    expect(livewire($page)->html())->not->toContain('fi-ta-grouping-settings');
})->with('filter bar pages');

it('offers deleted records to every signed-in member, a viewer too', function (string $page): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect(livewire($page)->instance()->getFilterBarData()['deletable'])->toBeTrue();

    $this->actingAs($viewer);

    expect(livewire($page)->instance()->getFilterBarData()['deletable'])->toBeTrue();
})->with('filter bar pages');

it('offers deleted records to nobody once the member is signed out', function (): void {
    $component = livewire(ListCompanies::class);

    Filament::auth()->logout();

    expect($component->instance()->getFilterBarData()['deletable'])->toBeFalse();
});

it('stores a grouping set from the group control on the view and reads the view as unchanged on return', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Task, 'state' => new TableStateData]);

    $component = livewire(ManageTasks::class)->call('applySavedView', $view->getKey());

    expect($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('setFilterBarGrouping', 'status_group', 'desc');

    expect($view->refresh()->state->grouping)->toBe('status_group:desc');

    $revisit = livewire(ManageTasks::class)->call('applySavedView', $view->getKey());

    expect($revisit->get('tableGrouping'))->toBe('status_group:desc')
        ->and($revisit->instance()->isSavedViewModified())->toBeFalse();
});

it('hands the sort and group controls an icon for each column that has one', function (): void {
    $sortable = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['sortable'])->keyBy('key');

    expect($sortable['name']['icon'])->not->toBeEmpty()
        ->and(collect(livewire(ManageTasks::class)->instance()->getFilterBarData()['groups'])->keyBy('id')['status_group']['icon'])->not->toBeEmpty();
});

it('draws every custom field icon from the app own outline set', function (): void {
    filterBarField($this->user, 'opportunity', 'budget', 'currency');
    filterBarField($this->user, 'opportunity', 'pick', 'select', ['Won', 'Lost']);
    filterBarField($this->user, 'opportunity', 'due', 'date');

    $data = livewire(ListOpportunities::class)->instance()->getFilterBarData();
    $fields = collect($data['fields'])->keyBy('key');

    expect($fields['custom_fields.budget']['icon'])->toBe(IconPath::for(CustomFieldType::CURRENCY->icon()))
        ->and($fields['custom_fields.pick']['icon'])->toBe(IconPath::for(CustomFieldType::SELECT->icon()))
        ->and($fields['custom_fields.due']['icon'])->toBe(IconPath::for(CustomFieldType::DATE->icon()))
        ->and(collect($data['sortable'])->keyBy('key')['custom_fields.budget']['icon'])->toBe(IconPath::for(CustomFieldType::CURRENCY->icon()));
});

it('hands the chip bar the period units with their limits ', function (): void {
    $data = livewire(ListOpportunities::class)->instance()->getFilterBarData();
    $units = collect($data['periodUnits'])->keyBy('value');

    expect(array_keys($units->all()))->toBe(['days', 'weeks', 'months', 'quarters', 'years'])
        ->and($units['days'])->toMatchArray(['label' => 'days', 'singular' => 'day', 'max' => 3650])
        ->and($units['years']['max'])->toBe(100)
        ->and(collect($data['fields'])->firstWhere('key', 'stale_days')['limit'])->toBe(StaleDaysFilter::MAX_DAYS);
});

it('ignores a sort written straight from the browser and keeps the one a header click set', function (): void {
    livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'desc')
        ->set('tableSort', 'name:asc')
        ->assertSet('tableSort', 'name:desc')
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])
        ->assertSet('tableSort', 'name:asc');
});

it('orders by each sort in turn and breaks ties with the next one', function (): void {
    $older = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'created_at' => now()->subDays(3)]);
    $newer = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'created_at' => now()->subDay()]);
    $other = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Beta', 'created_at' => now()->subDays(5)]);

    $component = livewire(ListCompanies::class);
    $order = fn (array $sorts): array => $component->call('setFilterBarSorts', $sorts)->instance()->getTableRecords()->modelKeys();

    expect($order([['column' => 'name', 'direction' => 'asc'], ['column' => 'created_at', 'direction' => 'asc']]))->toBe([$older->getKey(), $newer->getKey(), $other->getKey()])
        ->and($order([['column' => 'name', 'direction' => 'asc'], ['column' => 'created_at', 'direction' => 'desc']]))->toBe([$newer->getKey(), $older->getKey(), $other->getKey()])
        ->and($order([['column' => 'created_at', 'direction' => 'asc'], ['column' => 'name', 'direction' => 'asc']]))->toBe([$other->getKey(), $older->getKey(), $newer->getKey()]);
});

it('sorts by every sortable column at once, relations and custom fields included', function (string $page, string $model): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();
    $component = livewire($page);
    $sortable = array_column($component->instance()->getFilterBarData()['sortable'], 'key');

    $component->call('setFilterBarSorts', array_map(fn (string $column): array => ['column' => $column, 'direction' => 'desc'], $sortable))
        ->assertCanSeeTableRecords([$record]);

    expect(array_column($component->instance()->getFilterBarData()['sorts'], 'column'))->toBe($sortable);
})->with('filter bar pages');

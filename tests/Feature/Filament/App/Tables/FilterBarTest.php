<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
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
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\EntityFilters;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\Filters\TreeFilter;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Helpers\WorkspaceCustomField;

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

it('reads a view saved before the conditions filter existed as unchanged', function (string $page, CrmEntity $entity): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => $entity,
        'state' => new TableStateData(filters: ['trashed' => ['value' => 0]]),
    ]);

    $component = livewire($page)->call('applySavedView', $view->getKey());

    expect($component->instance()->isSavedViewModified())->toBeFalse();
})->with([
    'companies' => [ListCompanies::class, CrmEntity::Company],
    'people' => [ListPeople::class, CrmEntity::People],
    'opportunities' => [ListOpportunities::class, CrmEntity::Opportunity],
    'tasks' => [ManageTasks::class, CrmEntity::Task],
    'notes' => [ManageNotes::class, CrmEntity::Note],
]);

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

    expect(filterBarShownNames($none))->toBe(['Lone Second'])
        ->and(filterBarShownNames($foreign))->toBe([]);
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
        ->and(array_keys($found[0]))->toBe(['id', 'label'])
        ->and(array_column(filterBarSearch($this, ListPeople::class, 'company'), 'label'))->toBe(['Acme Local', 'Globex']);
});

it('searches members of this workspace and no one else', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => 'Casey Colleague']);
    User::factory()->withWorkspace()->create(['name' => 'Casey Stranger']);

    $found = filterBarSearch($this, ListCompanies::class, 'creator', 'casey');

    expect($found)->toBe([['id' => $colleague->getKey(), 'label' => 'Casey Colleague']]);
});

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

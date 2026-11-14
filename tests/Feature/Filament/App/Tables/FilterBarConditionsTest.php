<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
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
use App\Models\User;
use App\Models\Workspace;
use App\Queries\EntityFilters;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\Filters\TreeFilter;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;
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

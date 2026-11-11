<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\CrmEntity;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Enums\PeriodUnit;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleDisplay;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Models\User;
use App\Queries\Crm\LinkOptionsQuery;
use App\Queries\Filters\StaleDaysFilter;
use App\Support\IconPath;
use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Grouping\Group;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use RuntimeException;

trait HasFilterBar
{
    /** @var array<string, string> */
    private array $nativeColumnLabels = [];

    private mixed $healedInput = null;

    /** @var array{match: string, rules: list<array<string, mixed>>}|null */
    private ?array $healedOutput = null;

    private ?string $sortBeforeClientWrite = null;

    private bool $clientWroteSort = false;

    /**
     * @return array<string, FilterField>
     */
    public function filterFieldCatalog(): array
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? FilterFieldCatalog::for($this->tableEntity(), $user, $this->nativeColumnLabels)
            : [];
    }

    /**
     * @return list<array{id: string, label: string, image: ?string, icon: ?string, circular: bool}>
     */
    #[Renderless]
    public function searchFilterOptions(string $field, string $search = ''): array
    {
        $user = Filament::auth()->user();
        $definition = $this->filterFieldCatalog()[$field] ?? null;

        if (! $user instanceof User || ! $definition instanceof FilterField) {
            return [];
        }

        return match ($definition->kind) {
            FilterFieldKind::Member => resolve(LinkOptionsQuery::class)->search($user, null, $search),
            FilterFieldKind::Relation, FilterFieldKind::Record => $definition->related instanceof CrmEntity
                ? resolve(LinkOptionsQuery::class)->search($user, $definition->related, $search)
                : [],
            FilterFieldKind::Text, FilterFieldKind::Number, FilterFieldKind::Date, FilterFieldKind::Choice,
            FilterFieldKind::MultiChoice, FilterFieldKind::Checkbox, FilterFieldKind::MultiText, FilterFieldKind::Inactivity => [],
        };
    }

    /**
     * @return array{view: ?string, fields: list<array{key: string, label: string, icon: string, kind: string, limit: ?int, options: list<array{id: string, label: string, color: ?string, image?: ?string, icon?: ?string, circular?: bool}>, conditions: list<array{value: string, label: string, takesValue: bool, shape: string}>}>, rules: list<array<string, mixed>>, deleted: ?string, deletable: bool, sort: ?array{column: string, direction: string, label: string}, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}, periodUnits: list<array{value: string, label: string, singular: string, max: int}>}
     */
    #[Unexposed]
    public function getFilterBarData(): array
    {
        $user = Filament::auth()->user();
        $catalog = $this->filterFieldCatalog();

        if (! $user instanceof User || $catalog === []) {
            return ['view' => null, 'fields' => [], 'rules' => [], 'deleted' => null, 'deletable' => false, ...$this->filterBarOrdering($catalog), 'periodUnits' => []];
        }

        $state = $this->canonicalTableFilters($this->tableFilters);
        $rules = $state['conditions']['rules'];
        $described = FilterRuleDisplay::describe($rules, $catalog, $user);

        return [
            'view' => $this->activeSavedViewId === null ? null : (string) $this->activeSavedViewId,
            'fields' => array_values(array_map(fn (FilterField $field): array => [
                'key' => $field->key,
                'label' => $field->label,
                'icon' => $this->filterFieldIconPath($field->icon),
                'kind' => $field->kind->value,
                'limit' => $field->kind === FilterFieldKind::Inactivity ? StaleDaysFilter::MAX_DAYS : null,
                'options' => $this->filterFieldOptions($field, $user),
                'conditions' => array_map(fn (FilterCondition $condition): array => [
                    'value' => $condition->value,
                    'label' => $condition->label(),
                    'takesValue' => $condition->takesValue(),
                    'shape' => $condition->valueShape($field->kind),
                ], $field->conditions()),
            ], $catalog)),
            'rules' => array_map(
                fn (array $rule, array $described): array => [...$rule, ...$described],
                $rules,
                $described,
            ),
            'deleted' => FilterRules::deletedMode($state['trashed']['value']),
            'deletable' => $this->getTable()->getFilter('trashed')?->isVisible() ?? false,
            ...$this->filterBarOrdering($catalog),
            'periodUnits' => array_map(fn (PeriodUnit $unit): array => [
                'value' => $unit->value,
                'label' => __("filament/panel.tables.filters.units.{$unit->value}"),
                'singular' => __("filament/panel.tables.filters.this_unit.{$unit->value}"),
                'max' => $unit->maxAmount(),
            ], PeriodUnit::cases()),
        ];
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array{sort: ?array{column: string, direction: string, label: string}, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}}
     */
    private function filterBarOrdering(array $catalog): array
    {
        $sortable = $this->sortableTableColumns();
        $column = $this->getTableSortColumn();
        $group = $this->getTableGrouping();
        $groups = array_map(fn (Group $group): string => ColumnHeaderLabel::text($group->getLabel()), $this->getTable()->getGroups());

        return [
            'sort' => isset($sortable[$column]) ? ['column' => $column, 'direction' => $this->getTableSortDirection() ?? 'asc', 'label' => $sortable[$column]] : null,
            'sortable' => array_map(fn (string $key, string $label): array => [
                'key' => $key,
                'label' => $label,
                'icon' => $this->orderingIcon($catalog, [$key]) ?? $this->filterFieldIconPath(FilterFieldCatalog::columnIcon($key)),
            ], array_keys($sortable), $sortable),
            'groups' => array_map(fn (string $id, string $label): array => [
                'id' => $id,
                'label' => $label,
                'icon' => $this->orderingIcon($catalog, [Str::beforeLast($id, '_group'), FilterFieldCatalog::CUSTOM_FIELD_PREFIX.Str::beforeLast($id, '_group')]),
            ], array_keys($groups), $groups),
            'grouping' => $group instanceof Group
                ? ['id' => $group->getId(), 'direction' => $this->getTableGroupingDirection() ?? 'asc', 'label' => $groups[$group->getId()]]
                : null,
        ];
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @param  list<string>  $names
     */
    private function orderingIcon(array $catalog, array $names): ?string
    {
        foreach ($catalog as $field) {
            if (in_array($field->key, $names, true) || in_array(FilterFieldCatalog::columnFor($field->key), $names, true)) {
                return $this->filterFieldIconPath($field->icon);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function sortableTableColumns(): array
    {
        $columns = [];

        foreach ($this->getTable()->getVisibleColumns() as $column) {
            if ($column->isSortable()) {
                $columns[$column->getName()] = ColumnHeaderLabel::text($column->getLabel());
            }
        }

        return $columns;
    }

    /**
     * @return list<array{id: string, label: string, color: ?string, image?: ?string, icon?: ?string, circular?: bool}>
     */
    private function filterFieldOptions(FilterField $field, ?User $user): array
    {
        return match ($field->kind) {
            FilterFieldKind::Choice, FilterFieldKind::MultiChoice => $field->options,
            FilterFieldKind::Member => $user instanceof User ? [[...FilterRuleDisplay::viewer($user)->toArray(), 'color' => null]] : [],
            FilterFieldKind::Text, FilterFieldKind::Number, FilterFieldKind::Date, FilterFieldKind::Checkbox,
            FilterFieldKind::MultiText, FilterFieldKind::Relation, FilterFieldKind::Record, FilterFieldKind::Inactivity => [],
        };
    }

    private function filterFieldIconPath(string $icon): string
    {
        try {
            return IconPath::for($icon);
        } catch (RuntimeException) {
            return IconPath::for(FilterFieldCatalog::columnIcon('name'));
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function normalizeTableFilterValuesFromQueryString(array &$data): void
    {
        // Filament turns the words true, false and null into values, and a rule's text can be any of them.
    }

    #[Unexposed]
    public function updatedTableFilters(): void
    {
        $this->tableFilters = $this->canonicalTableFilters($this->tableFilters);

        parent::updatedTableFilters();
    }

    #[Unexposed]
    public function updatingTableSort(): void
    {
        $this->sortBeforeClientWrite = $this->tableSort;
        $this->clientWroteSort = true;
    }

    #[Unexposed]
    public function updatedTableSort(): void
    {
        // Filament's hidden sort settings write tableSort back from Alpine and force ascending on a new column.
        // Here only a header click (sortTable) and the Sort control may change the sort.
        if ($this->clientWroteSort) {
            $this->clientWroteSort = false;
            $this->tableSort = $this->sortBeforeClientWrite;

            return;
        }

        parent::updatedTableSort();
    }

    public function setFilterRules(mixed $rules = []): void
    {
        $rules = is_array($rules) ? $rules : [];

        $this->tableFilters = [
            ...$this->canonicalTableFilters($this->tableFilters),
            ConditionsFilter::NAME => ['match' => 'all', 'rules' => array_values($rules)],
        ];

        $this->updatedTableFilters();
    }

    public function setDeletedRecordsFilter(mixed $mode = null): void
    {
        $mode = is_string($mode) ? $mode : null;

        $this->tableFilters = [
            ...$this->canonicalTableFilters($this->tableFilters),
            'trashed' => ['value' => FilterRules::deletedValue($mode)],
        ];

        $this->updatedTableFilters();
    }

    public function setFilterBarSort(mixed $column = null, mixed $direction = null): void
    {
        if (! is_string($column)) {
            $this->tableSort = null;
        } elseif (array_key_exists($column, $this->sortableTableColumns()) && in_array($direction ??= 'asc', ['asc', 'desc'], true)) {
            $this->tableSort = "{$column}:{$direction}";
        } else {
            return;
        }

        $this->updatedTableSort();
    }

    public function setFilterBarGrouping(mixed $group = null, mixed $direction = null): void
    {
        if (! is_string($group)) {
            $this->tableGrouping = null;
        } elseif (array_key_exists($group, $this->getTable()->getGroups()) && in_array($direction ??= 'asc', ['asc', 'desc'], true)) {
            $this->tableGrouping = "{$group}:{$direction}";
        } else {
            return;
        }

        $this->updatedTableGrouping();
    }

    public function clearFilterBar(): void
    {
        $this->tableFilters = null;

        $this->updatedTableFilters();
    }

    #[Unexposed]
    public function hasFilterBarState(): bool
    {
        $state = $this->canonicalTableFilters($this->tableFilters);

        return $state['conditions']['rules'] !== [] || filled($state['trashed']['value']);
    }

    /**
     * @return array{conditions: array{match: string, rules: list<array<string, mixed>>}, trashed: array{value: mixed}}
     */
    protected function canonicalTableFilters(mixed $filters): array
    {
        $legacy = FilterRules::fromLegacy(is_array($filters) ? $filters : [], $this->filterFieldCatalog());

        return [...$legacy, ConditionsFilter::NAME => $this->healedFilterRules($legacy['conditions'])];
    }

    /**
     * @return array{match: string, rules: list<array<string, mixed>>}
     */
    protected function healedFilterRules(mixed $conditions): array
    {
        if ($this->healedOutput !== null && ($conditions === $this->healedInput || $conditions === $this->healedOutput)) {
            return $this->healedOutput;
        }

        $user = Filament::auth()->user();
        $healed = $user instanceof User
            ? FilterRules::heal($conditions, $this->filterFieldCatalog(), $user)
            : ['match' => 'all', 'rules' => []];

        $this->healedInput = $conditions;

        return $this->healedOutput = $healed;
    }
}

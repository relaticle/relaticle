<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\CrmEntity;
use App\Enums\FilterCondition;
use App\Enums\FilterFieldKind;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleDisplay;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Models\User;
use App\Queries\Crm\LinkOptionsQuery;
use App\Support\IconPath;
use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Renderless;
use RuntimeException;

trait HasFilterBar
{
    /** @var array<string, string> */
    private array $nativeColumnLabels = [];

    private mixed $healedInput = null;

    /** @var array{match: string, rules: list<array<string, mixed>>}|null */
    private ?array $healedOutput = null;

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
     * @return list<array{id: string, label: string}>
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
     * @return array{view: ?string, fields: list<array<string, mixed>>, rules: list<array<string, mixed>>, deleted: ?string}
     */
    #[Unexposed]
    public function getFilterBarData(): array
    {
        $user = Filament::auth()->user();
        $catalog = $this->filterFieldCatalog();

        if (! $user instanceof User || $catalog === []) {
            return ['view' => null, 'fields' => [], 'rules' => [], 'deleted' => null];
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
                'editable' => $field->kind->hasValueEditor(),
                'options' => $this->filterFieldOptions($field),
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
        ];
    }

    /**
     * @return list<array{id: string, label: string, color: ?string}>
     */
    private function filterFieldOptions(FilterField $field): array
    {
        return match ($field->kind) {
            FilterFieldKind::Choice, FilterFieldKind::MultiChoice => $field->options,
            FilterFieldKind::Member => [['id' => '@me', 'label' => __('filament/panel.tables.filters.me'), 'color' => null]],
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

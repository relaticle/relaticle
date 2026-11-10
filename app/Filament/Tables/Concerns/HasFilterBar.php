<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\CrmEntity;
use App\Enums\FilterFieldKind;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Models\User;
use App\Queries\Crm\LinkOptionsQuery;
use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Renderless;

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

    #[Unexposed]
    public function updatedTableFilters(): void
    {
        $this->tableFilters = $this->canonicalTableFilters($this->tableFilters);

        parent::updatedTableFilters();
    }

    /**
     * @param  array<array-key, mixed>  $rules
     */
    public function setFilterRules(array $rules): void
    {
        $this->tableFilters = [
            ...$this->canonicalTableFilters($this->tableFilters),
            ConditionsFilter::NAME => ['match' => 'all', 'rules' => array_values($rules)],
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

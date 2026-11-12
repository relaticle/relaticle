<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\FiltersWithRules;
use App\Filament\Tables\FilterBar\FilterField;

trait HasBoardViews
{
    use FiltersWithRules;

    private const array GROUPING_FIELDS = ['company' => 'company', 'assignee' => 'assignees'];

    public function mountHasBoardViews(): void
    {
        $this->tableSearch = '';

        if (filled($this->tableFilters)) {
            $this->tableFilters = $this->canonicalTableFilters($this->tableFilters);
        }
    }

    protected function filterBarEntity(): CrmEntity
    {
        return $this->viewSwitcherEntity();
    }

    protected function filterBarViewKey(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array{sorts: list<array{column: string, direction: string, label: string}>, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}, groupDirections: bool}
     */
    protected function filterBarOrdering(array $catalog): array
    {
        $groups = [];
        $grouping = null;

        foreach ($this->getBoard()->getGroupings() as $name => $definition) {
            $label = ColumnHeaderLabel::text($definition->getLabel());
            $groups[] = ['id' => $name, 'label' => $label, 'icon' => $this->orderingIcon($catalog, [self::GROUPING_FIELDS[$name] ?? $name])];

            if ($name === $this->groupBy) {
                $grouping = ['id' => $name, 'direction' => 'asc', 'label' => $label];
            }
        }

        return ['sorts' => [], 'sortable' => [], 'groups' => $groups, 'grouping' => $grouping, 'groupDirections' => false];
    }

    public function setFilterBarGrouping(mixed $group = null, mixed $direction = null): void
    {
        if ($group !== null && (! is_string($group) || ! array_key_exists($group, $this->getBoard()->getGroupings()))) {
            return;
        }

        $this->groupBy = $group;
        $this->updatedGroupBy();
    }
}

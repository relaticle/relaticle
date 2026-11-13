<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\SortList;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasFilterBar
{
    use FiltersWithRules;

    private ?string $sortBeforeClientWrite = null;

    private bool $clientWroteSort = false;

    protected function filterBarEntity(): CrmEntity
    {
        return $this->tableEntity();
    }

    protected function filterBarViewKey(): ?string
    {
        return $this->activeSavedViewId === null ? null : (string) $this->activeSavedViewId;
    }

    /**
     * @return array<string, string>
     */
    protected function filterBarColumnLabels(): array
    {
        return $this->mergedTableColumnLabels();
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array{sorts: list<array{column: string, direction: string, label: string}>, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}}
     */
    protected function filterBarOrdering(array $catalog): array
    {
        $sortable = $this->sortableTableColumns();
        $sorts = $this->activeTableSorts();
        $group = $this->getTableGrouping();
        $groups = array_map(fn (Group $group): string => ColumnHeaderLabel::text($group->getLabel()), $this->getTable()->getGroups());

        return [
            'sorts' => array_map(
                fn (string $column, string $direction): array => ['column' => $column, 'direction' => $direction, 'label' => $sortable[$column]],
                array_keys($sorts),
                $sorts,
            ),
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

    public function setFilterBarSorts(mixed $sorts = []): void
    {
        $list = SortList::fromClient($sorts, $this->sortableTableColumns());

        $this->writeTableSorts($list);
        $this->updatedTableSort();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        $table = $this->getTable();

        if ($table->isGroupsOnly() || $this->isTableReordering()) {
            return parent::applySortingToTableQuery($query);
        }

        foreach ($this->activeTableSorts() as $column => $direction) {
            $table->getSortableVisibleColumn($column)?->applySort($query, $direction);
        }

        // With the sort cleared, Filament adds only its default sort and the key, after the member's sorts.
        $sort = $this->tableSort;
        $this->tableSort = null;

        try {
            return parent::applySortingToTableQuery($query);
        } finally {
            $this->tableSort = $sort;
        }
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    private function activeTableSorts(): array
    {
        return array_intersect_key($this->tableSorts(), $this->sortableTableColumns());
    }

    public function setFilterBarGrouping(mixed $group = null, mixed $direction = null): void
    {
        if (! is_string($group)) {
            $this->tableGrouping = null;
        } elseif (array_key_exists($group, $this->getTable()->getGroups()) && in_array($direction ??= 'asc', SortList::DIRECTIONS, true)) {
            $this->tableGrouping = "{$group}:{$direction}";
        } else {
            return;
        }

        $this->updatedTableGrouping();
    }
}

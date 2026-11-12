<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\FiltersWithRules;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Sorts\CustomFieldSort;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;

trait HasBoardViews
{
    use FiltersWithRules;

    private const array GROUPING_FIELDS = ['company' => 'company', 'assignee' => 'assignees'];

    #[Url(as: 'sort')]
    public ?string $boardSort = null;

    public function mountHasBoardViews(): void
    {
        $this->tableSearch = '';

        if (filled($this->tableFilters)) {
            $this->tableFilters = [...$this->canonicalTableFilters($this->tableFilters), 'trashed' => ['value' => null]];
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
     * @return array{sorts: list<array{column: string, direction: string, label: string}>, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}}
     */
    protected function filterBarOrdering(array $catalog): array
    {
        $fields = $this->boardSortableFields();
        $lanesShown = $this->groupBy !== null;

        return [
            'sorts' => array_map(
                fn (string $column, string $direction): array => ['column' => $column, 'direction' => $direction, 'label' => $fields[$column]['label']],
                array_keys($this->activeBoardSorts()),
                $this->activeBoardSorts(),
            ),
            'sortable' => $lanesShown ? [] : array_map(
                fn (string $key, array $field): array => ['key' => $key, ...$field],
                array_keys($fields),
                $fields,
            ),
            'groups' => [],
            'grouping' => null,
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    protected function boardSortableFields(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $entity = $this->filterBarEntity();
        $catalog = $this->filterFieldCatalog();
        $codes = resolve(CustomFieldFilterSchema::class)->sortableFields($user, $entity->value)->keys()->all();
        $fields = [];

        foreach ([...$entity->query()::sorts(), ...array_map(fn (string $code): string => FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$code, $codes)] as $key) {
            if (isset($catalog[$key])) {
                $fields[$key] = ['label' => $catalog[$key]->label, 'icon' => $this->filterFieldIconPath($catalog[$key]->icon)];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    private function activeBoardSorts(): array
    {
        return $this->groupBy === null ? array_intersect_key(SortList::parse($this->boardSort), $this->boardSortableFields()) : [];
    }

    public function setFilterBarSorts(mixed $sorts = []): void
    {
        $this->boardSort = SortList::format(SortList::fromClient($sorts, $this->boardSortableFields()));
        $this->rebuildBoard();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function sortBoardQuery(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query;
        }

        $customFields = resolve(CustomFieldFilterSchema::class)->sortableFields($user, $this->filterBarEntity()->value);

        foreach ($this->activeBoardSorts() as $key => $direction) {
            $field = $customFields->get(Str::after($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX));

            if (str_starts_with($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX) && $field !== null) {
                new CustomFieldSort($field)($query, $direction === 'desc', $key);
            } else {
                $query->orderBy($query->getModel()->qualifyColumn($key), $direction);
            }
        }

        return $query;
    }

    protected function refuseReorderWhileSorted(Model $card, string $targetColumnId): void
    {
        $sorts = $this->activeBoardSorts();

        if ($sorts === [] || $this->getBoard()->getRecordColumnId($card) !== $targetColumnId) {
            return;
        }

        $fields = $this->boardSortableFields();

        throw CardMoveRejectedException::because(__('filament/pages/boards.sorted_no_reorder', [
            'field' => implode(', ', array_map(fn (string $column): string => $fields[$column]['label'], array_keys($sorts))),
        ]));
    }

    public function setBoardGrouping(mixed $group = null): void
    {
        if ($group !== null && (! is_string($group) || ! array_key_exists($group, $this->getBoard()->getGroupings()))) {
            return;
        }

        $this->groupBy = $group;
        $this->updatedGroupBy();
        $this->rebuildBoard();
    }

    /**
     * @return list<array{id: string, label: string, icon: ?string}>
     */
    protected function boardGroupings(): array
    {
        $catalog = $this->filterFieldCatalog();
        $groupings = [];

        foreach ($this->getBoard()->getGroupings() as $name => $definition) {
            $groupings[] = [
                'id' => $name,
                'label' => ColumnHeaderLabel::text($definition->getLabel()),
                'icon' => $this->orderingIcon($catalog, [self::GROUPING_FIELDS[$name] ?? $name]),
            ];
        }

        return $groupings;
    }

    private function rebuildBoard(): void
    {
        $this->board = $this->board($this->makeBoard());
        $this->resetTable();
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\Views\SaveTablePreference;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Providers\Filament\AppPanelProvider;
use App\Queries\Views\StoredTablePreferenceQuery;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use LogicException;

trait PersistsTableState
{
    #[Locked]
    public ?string $tableStateHash = null;

    #[Locked]
    public ?string $activeSavedViewId = null;

    #[Locked]
    public ?string $appliedSavedViewHash = null;

    #[Locked]
    public ?string $defaultSavedViewId = null;

    private ?TableStateData $initialTableState = null;

    private ?TablePreference $storedTablePreference = null;

    private bool $hasLoadedTablePreference = false;

    private bool $seededFromSavedView = false;

    private bool $savesTableStateOnOpen = false;

    private ?TableStateData $ownTableState = null;

    private bool $ownTableStateKnown = false;

    private bool $ownTableStateDirty = false;

    public function mountInteractsWithTable(): void
    {
        parent::mountInteractsWithTable();

        $initial = $this->initialTableState();

        $this->tableSort = blank($this->tableSort) ? $initial->sort : $this->tableSort;
        $this->tableFilters = $this->canonicalTableFilters(blank($this->tableFilters) ? $initial->filters : $this->tableFilters);
        $this->tableSearch = blank($this->tableSearch) ? $initial->search : $this->tableSearch;
        $this->tableColumnSearches = blank($this->tableColumnSearches) ? $initial->columnSearches : $this->tableColumnSearches;
        $this->tableGrouping = blank($this->tableGrouping) ? $initial->grouping : $this->tableGrouping;
        $this->tableRecordsPerPage = blank($this->tableRecordsPerPage) ? $initial->perPage : $this->tableRecordsPerPage;
        $this->tableColumnWidths = $this->storedTablePreference()?->state->widths ?? [];
        $this->tableColumnLabels = $initial->labels;
        $this->tablePinnedColumns = $initial->pinned;
    }

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        $table = $this->getTable();

        $this->tableColumnWidths = $this->onlyTableColumns($this->tableColumnWidths);
        $this->tableColumnLabels = $this->onlyTableColumns($this->tableColumnLabels);

        if (filled($this->tableSort) && ! array_key_exists(explode(':', $this->tableSort)[0], $table->getColumns())) {
            $this->tableSort = null;
        }

        if ($table->isPaginated() && ! $this->isOfferedTablePageSize($this->tableRecordsPerPage)) {
            $this->tableRecordsPerPage = $this->getDefaultTableRecordsPerPageSelectOption();
        }

        $this->openRequestedSavedView();

        if ($this->seededFromSavedView) {
            $this->markSavedViewApplied();
        }
    }

    public function dehydratePersistsTableState(): void
    {
        $state = $this->currentTableState();
        $hash = hash('xxh128', "{$state->hash()}|{$this->activeSavedViewId}|{$this->appliedSavedViewHash}");

        if ($this->savesTableStateOnOpen || $this->ownTableStateDirty || ($this->tableStateHash !== null && $this->tableStateHash !== $hash)) {
            resolve(SaveTablePreference::class)->execute(
                $this->savedViewUser(),
                $this->savedViewWorkspace(),
                $this->tableEntity(),
                $state,
                $this->getActiveSavedView(),
                $this->appliedSavedViewHash,
                $this->ownTableStateToStore(),
            );
        }

        $this->tableStateHash = $hash;
        $this->savesTableStateOnOpen = false;
        $this->ownTableStateDirty = false;
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    protected function loadTableColumnsFromSession(): array
    {
        return $this->tableColumnsFrom($this->initialTableState());
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $byColumn
     * @return array<string, TValue>
     */
    private function onlyTableColumns(array $byColumn): array
    {
        return array_intersect_key($byColumn, $this->getTable()->getColumns());
    }

    private function applyTableState(TableStateData $state): void
    {
        $this->tableColumnLabels = $this->onlyTableColumns($state->labels);
        $this->cachedDefaultTableColumnState = null;
        $this->tablePinnedColumns = $state->pinned;
        $this->applyTableColumnManager($this->tableColumnsFrom($state), wasReordered: true);
        $this->stampResizableColumns($this->getTable());

        $this->tableSort = $state->sort;
        $this->updatedTableSort();

        $this->tableGrouping = $state->grouping;
        $this->updatedTableGrouping();

        if (filled($state->search)) {
            $this->tableSearch = $state->search;
            $this->updatedTableSearch();
        } else {
            $this->resetTableSearch();
        }

        if ($state->columnSearches !== []) {
            $this->tableColumnSearches = $state->columnSearches;
            $this->updatedTableColumnSearches();
        } else {
            $this->resetTableColumnSearches();
        }

        $this->tableRecordsPerPage = $this->isOfferedTablePageSize($state->perPage)
            ? $state->perPage
            : $this->getDefaultTableRecordsPerPageSelectOption();
        $this->updatedTableRecordsPerPage();

        $this->getTableFiltersForm()->fill($this->canonicalTableFilters($state->filters));
        $this->updatedTableFilters();

        $this->flushCachedTableRecords();
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function tableColumnsFrom(TableStateData $state): array
    {
        $defaults = $this->getDefaultTableColumnState();

        if ($state->columns === []) {
            return $defaults;
        }

        $byName = array_column($defaults, null, 'name');
        $columns = [];

        foreach ($state->columns as $item) {
            $name = $item['name'] ?? null;

            if ($name !== null && isset($byName[$name])) {
                $columns[] = [...$byName[$name], 'isToggled' => $item['isToggled'] ?? true];
            }
        }

        return $columns;
    }

    private function isOfferedTablePageSize(int|string|null $perPage): bool
    {
        return in_array($perPage, AppPanelProvider::pageSizeOptions($this->getTable()));
    }

    public function getTableTitleColumn(): string
    {
        return $this->tableEntity()->titleColumn();
    }

    private function tableEntity(): CrmEntity
    {
        $model = $this->getModel();

        return CrmEntity::tryFromModel(new $model)
            ?? throw new LogicException("{$model} is not a CRM entity, so its table state cannot be remembered.");
    }

    private function storedTablePreference(): ?TablePreference
    {
        if (! $this->hasLoadedTablePreference) {
            $this->hasLoadedTablePreference = true;
            $this->storedTablePreference = resolve(StoredTablePreferenceQuery::class)
                ->get($this->savedViewUser(), $this->savedViewWorkspace(), $this->tableEntity());
        }

        return $this->storedTablePreference;
    }

    private function initialTableState(): TableStateData
    {
        if ($this->initialTableState instanceof TableStateData) {
            return $this->initialTableState;
        }

        $preference = $this->storedTablePreference();
        $active = $this->getVisibleSavedViews()->find($preference?->active_view_id);
        $default = $this->getVisibleSavedViews()->find($preference?->default_view_id);

        $this->defaultSavedViewId = $default?->getKey();

        $hasSavedState = $preference !== null && $preference->state->columns !== [];
        $isCleanCopy = $hasSavedState && $active instanceof SavedView && $preference->state->withoutWidths()->hash() === $preference->applied_view_hash;

        // A default starts each session over the last state, which is saved so the rest of the session keeps it.
        if ($this->opensTableFirstThisSession() && $default instanceof SavedView) {
            $this->savesTableStateOnOpen = $hasSavedState && (! $isCleanCopy || ! $active->is($default));

            if ($hasSavedState && ! $active instanceof SavedView) {
                $this->rememberOwnTableState($preference->state->withoutWidths());
            }

            return $this->initialTableState = $this->seedFromSavedView($default);
        }

        if ($hasSavedState) {
            return $this->initialTableState = $isCleanCopy
                ? $this->seedFromSavedView($active)
                : $this->seedFromPreference($preference, $active);
        }

        return $this->initialTableState = $default instanceof SavedView
            ? $this->seedFromSavedView($default)
            : new TableStateData;
    }

    private function rememberOwnTableState(?TableStateData $state): void
    {
        $this->ownTableState = $state;
        $this->ownTableStateKnown = true;
        $this->ownTableStateDirty = true;
    }

    private function ownTableStateToStore(): ?TableStateData
    {
        if ($this->ownTableStateKnown) {
            return $this->ownTableState;
        }

        return $this->getActiveSavedView() instanceof SavedView ? $this->storedTablePreference()?->own_state : null;
    }

    private function parkedOwnTable(): ?TableStateData
    {
        $this->hasLoadedTablePreference = false;
        $preference = $this->storedTablePreference();

        if (! $preference instanceof TablePreference) {
            return null;
        }

        return $preference->active_view_id === null ? $preference->state->withoutWidths() : $preference->own_state;
    }

    private function opensTableFirstThisSession(): bool
    {
        $key = 'tables.opened.'.$this->savedViewWorkspace()->getKey().'.'.$this->tableEntity()->value;
        $isFirst = ! session()->has($key);

        session()->put($key, true);

        return $isFirst;
    }

    private function seedFromSavedView(SavedView $view): TableStateData
    {
        $this->activeSavedViewId = $view->getKey();
        $this->seededFromSavedView = true;

        return $view->state;
    }

    private function seedFromPreference(TablePreference $preference, ?SavedView $active): TableStateData
    {
        $this->activeSavedViewId = $active?->getKey();
        $this->appliedSavedViewHash = $active instanceof SavedView ? $preference->applied_view_hash : null;

        return $preference->state;
    }

    private function markSavedViewApplied(): void
    {
        $this->appliedSavedViewHash = $this->currentTableState()->withoutWidths()->hash();
    }

    private function currentTableState(): TableStateData
    {
        return new TableStateData(
            columns: array_values(array_map(fn (array $item): array => Arr::only($item, ['type', 'name', 'isToggled']), $this->tableColumns)),
            sort: filled($this->tableSort) ? $this->tableSort : null,
            filters: $this->canonicalTableFilters($this->tableFilters),
            search: filled($this->tableSearch) ? $this->tableSearch : null,
            columnSearches: array_filter($this->tableColumnSearches, filled(...)),
            grouping: filled($this->tableGrouping) ? $this->tableGrouping : null,
            perPage: $this->tableRecordsPerPage,
            widths: $this->onlyTableColumns($this->tableColumnWidths),
            labels: $this->tableColumnLabels,
            pinned: $this->tablePinnedColumns,
        );
    }
}

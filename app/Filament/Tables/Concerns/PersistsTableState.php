<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\Views\SaveTablePreference;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Providers\Filament\AppPanelProvider;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use LogicException;

trait PersistsTableState
{
    #[Locked]
    public ?string $tableStateHash = null;

    #[Locked]
    public ?string $activeSavedViewId = null;

    #[Locked]
    public ?string $appliedSavedViewHash = null;

    // Filament's phone sort control rewrites a `tableSort` that is not one `column:direction`, so later sorts live here.
    #[Url(as: 'then_sort')]
    public ?string $tableThenSorts = null;

    private ?TableStateData $initialTableState = null;

    private bool $seededFromSavedView = false;

    private bool $savesTableStateOnOpen = false;

    private ?TableStateData $ownTableState = null;

    private bool $ownTableStateKnown = false;

    private bool $ownTableStateDirty = false;

    public function mountInteractsWithTable(): void
    {
        parent::mountInteractsWithTable();

        $initial = $this->initialTableState();

        if (blank($this->tableSort)) {
            $this->writeTableSorts(SortList::parse($initial->sort));
        }

        $this->tableFilters = $this->canonicalTableFilters(blank($this->tableFilters) ? $initial->filters : $this->tableFilters);
        // The lists have no search box, so a ?search= link would filter with nothing to clear it.
        $this->tableSearch = '';
        $this->tableColumnSearches = [];
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

        $this->writeTableSorts(array_intersect_key($this->tableSorts(), $table->getColumns()));

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
        $this->saveActiveSavedView();

        $state = $this->currentTableState();
        $hash = $this->tableStateSignature($state);

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

    private function tableStateSignature(TableStateData $state): string
    {
        return hash('xxh128', "{$state->hash()}|{$this->activeSavedViewId}|{$this->appliedSavedViewHash}");
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

        $this->writeTableSorts(SortList::parse($state->sort));
        $this->updatedTableSort();

        $this->tableGrouping = $state->grouping;
        $this->updatedTableGrouping();

        $this->resetTableSearch();
        $this->resetTableColumnSearches();

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

    private function initialTableState(): TableStateData
    {
        if ($this->initialTableState instanceof TableStateData) {
            return $this->initialTableState;
        }

        $preference = $this->storedTablePreference();
        $active = $this->tableSavedViews()->find($preference?->active_view_id);
        $default = $this->tableSavedViews()->find($this->viewOrder()[0] ?? null);

        $hasSavedState = $preference !== null && $preference->state->columns !== [];
        $isCleanCopy = $hasSavedState && $active instanceof SavedView && $preference->state->withoutWidths()->hash() === $preference->applied_view_hash;

        if ($this->isBareArrival() && $default instanceof SavedView) {
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
        $this->forgetStoredTablePreference();
        $preference = $this->storedTablePreference();

        if (! $preference instanceof TablePreference) {
            return null;
        }

        return $preference->active_view_id === null ? $preference->state->withoutWidths() : $preference->own_state;
    }

    private function seedFromSavedView(SavedView $view): TableStateData
    {
        $this->activeSavedViewId = $view->getKey();
        $this->seededFromSavedView = true;

        return $view->tableState();
    }

    private function seedFromPreference(TablePreference $preference, ?SavedView $active): TableStateData
    {
        $this->activeSavedViewId = $active?->getKey();
        $this->appliedSavedViewHash = $active instanceof SavedView ? $this->canonicalAppliedHash($active, $preference->applied_view_hash) : null;

        return $preference->state;
    }

    private function canonicalAppliedHash(SavedView $view, ?string $stored): ?string
    {
        if ($stored === null || $stored !== $view->tableState()->withoutWidths()->hash()) {
            return $stored;
        }

        $canonical = $view->tableState()->withoutWidths();
        $canonical->filters = $this->canonicalTableFilters($canonical->filters);

        return $canonical->hash();
    }

    private function markSavedViewApplied(): void
    {
        $this->appliedSavedViewHash = $this->currentTableState()->withoutWidths()->hash();
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    private function tableSorts(): array
    {
        return SortList::parse(implode(',', array_filter([$this->tableSort, $this->tableThenSorts])));
    }

    /**
     * @param  array<string, string>  $sorts
     */
    private function writeTableSorts(array $sorts): void
    {
        $this->tableSort = SortList::format(array_slice($sorts, 0, 1, preserve_keys: true));
        $this->tableThenSorts = SortList::format(array_slice($sorts, 1, preserve_keys: true));
    }

    private function currentTableState(): TableStateData
    {
        return new TableStateData(
            columns: array_values(array_map(fn (array $item): array => Arr::only($item, ['type', 'name', 'isToggled']), $this->tableColumns)),
            sort: SortList::format($this->tableSorts()),
            filters: $this->canonicalTableFilters($this->tableFilters),
            grouping: filled($this->tableGrouping) ? $this->tableGrouping : null,
            perPage: $this->tableRecordsPerPage,
            widths: $this->onlyTableColumns($this->tableColumnWidths),
            labels: $this->tableColumnLabels,
            pinned: $this->tablePinnedColumns,
        );
    }
}

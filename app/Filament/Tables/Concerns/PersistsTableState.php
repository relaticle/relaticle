<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\TableLayouts\SaveTablePreference;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Filament\AppPanelProvider;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;
use LogicException;

trait PersistsTableState
{
    #[Locked]
    public ?string $tableStateHash = null;

    #[Locked]
    public ?string $activeTableLayoutId = null;

    #[Locked]
    public ?string $appliedTableLayoutHash = null;

    #[Locked]
    public ?string $defaultTableLayoutId = null;

    private ?TableStateData $initialTableState = null;

    private ?TablePreference $storedTablePreference = null;

    private bool $hasLoadedTablePreference = false;

    private bool $seededFromTableLayout = false;

    public function mountInteractsWithTable(): void
    {
        parent::mountInteractsWithTable();

        $initial = $this->initialTableState();

        $this->tableSort = blank($this->tableSort) ? $initial->sort : $this->tableSort;
        $this->tableFilters = blank($this->tableFilters) ? ($initial->filters ?: null) : $this->tableFilters;
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

        $this->tableColumnWidths = array_intersect_key($this->tableColumnWidths, $table->getColumns());
        $this->tableColumnLabels = array_intersect_key($this->tableColumnLabels, $table->getColumns());

        if (filled($this->tableSort) && ! array_key_exists(explode(':', $this->tableSort)[0], $table->getColumns())) {
            $this->tableSort = null;
        }

        if ($table->isPaginated() && ! $this->isOfferedTablePageSize($this->tableRecordsPerPage)) {
            $this->tableRecordsPerPage = $this->getDefaultTableRecordsPerPageSelectOption();
        }

        if ($this->seededFromTableLayout) {
            $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
        }
    }

    public function dehydratePersistsTableState(): void
    {
        $state = $this->currentTableState();
        $hash = hash('xxh128', "{$state->hash()}|{$this->activeTableLayoutId}|{$this->appliedTableLayoutHash}");

        if ($this->tableStateHash !== null && $this->tableStateHash !== $hash) {
            /** @var User $user */
            $user = auth()->user();
            /** @var Workspace $workspace */
            $workspace = Filament::getTenant();

            resolve(SaveTablePreference::class)->execute(
                $user,
                $workspace,
                $this->tableEntity(),
                $state,
                $this->visibleTableLayout($this->activeTableLayoutId),
                $this->appliedTableLayoutHash,
            );
        }

        $this->tableStateHash = $hash;
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    protected function loadTableColumnsFromSession(): array
    {
        return $this->tableColumnsFrom($this->initialTableState());
    }

    private function applyTableState(TableStateData $state): void
    {
        $this->tableColumnLabels = array_intersect_key($state->labels, $this->getTable()->getColumns());
        $this->cachedDefaultTableColumnState = null;
        $this->tablePinnedColumns = $state->pinned;
        $this->applyTableColumnManager($this->tableColumnsFrom($state), wasReordered: true);

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

        $this->getTableFiltersForm()->fill($state->filters);
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

    private function tableEntity(): CrmEntity
    {
        $model = $this->getModel();

        return CrmEntity::tryFromModel(new $model)
            ?? throw new LogicException("{$model} is not a CRM entity, so its table state cannot be remembered.");
    }

    private function visibleTableLayout(?string $id): ?TableLayout
    {
        if ($id === null) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();

        return TableLayout::query()
            ->visibleTo($user, Filament::getTenant()->getKey(), $this->tableEntity())
            ->find($id);
    }

    private function storedTablePreference(): ?TablePreference
    {
        if (! $this->hasLoadedTablePreference) {
            $this->hasLoadedTablePreference = true;
            $this->storedTablePreference = TablePreference::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', Filament::getTenant()->getKey())
                ->whereBelongsTo(auth()->user())
                ->where('entity', $this->tableEntity())
                ->first();
        }

        return $this->storedTablePreference;
    }

    private function initialTableState(): TableStateData
    {
        if ($this->initialTableState instanceof TableStateData) {
            return $this->initialTableState;
        }

        $preference = $this->storedTablePreference();
        $active = $this->visibleTableLayout($preference?->active_layout_id);
        $default = $this->visibleTableLayout($preference?->default_layout_id);

        $this->defaultTableLayoutId = $default?->getKey();

        if ($preference !== null && $preference->state->columns !== []) {
            $isCleanCopy = $active instanceof TableLayout && $preference->state->withoutWidths()->hash() === $preference->applied_layout_hash;

            return $this->initialTableState = $isCleanCopy
                ? $this->seedFromTableLayout($active)
                : $this->seedFromPreference($preference, $active);
        }

        return $this->initialTableState = $default instanceof TableLayout
            ? $this->seedFromTableLayout($default)
            : new TableStateData;
    }

    private function seedFromTableLayout(TableLayout $layout): TableStateData
    {
        $this->activeTableLayoutId = $layout->getKey();
        $this->seededFromTableLayout = true;

        return $layout->state;
    }

    private function seedFromPreference(TablePreference $preference, ?TableLayout $active): TableStateData
    {
        $this->activeTableLayoutId = $active?->getKey();
        $this->appliedTableLayoutHash = $active instanceof TableLayout ? $preference->applied_layout_hash : null;

        return $preference->state;
    }

    private function currentTableState(): TableStateData
    {
        $columns = [];

        foreach ($this->tableColumns as $item) {
            $columns[] = ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']];
        }

        return new TableStateData(
            columns: $columns,
            sort: filled($this->tableSort) ? $this->tableSort : null,
            filters: $this->tableFilters ?? [],
            search: filled($this->tableSearch) ? $this->tableSearch : null,
            columnSearches: array_filter($this->tableColumnSearches, filled(...)),
            grouping: filled($this->tableGrouping) ? $this->tableGrouping : null,
            perPage: $this->tableRecordsPerPage,
            widths: array_intersect_key($this->tableColumnWidths, $this->getTable()->getColumns()),
            labels: $this->tableColumnLabels,
            pinned: $this->tablePinnedColumns,
        );
    }
}

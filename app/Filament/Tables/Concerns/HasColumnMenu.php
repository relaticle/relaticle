<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

trait HasColumnMenu
{
    public const int COLUMN_LABEL_MAX_LENGTH = 60;

    /** @var array<string, string> */
    #[Locked]
    public array $tableColumnLabels = [];

    /** @var list<string> */
    #[Locked]
    public array $tablePinnedColumns = [];

    /** @var array<string, string> */
    private array $originalTableColumnLabels = [];

    /** @var list<string>|null */
    private ?array $declaredTableColumnNames = null;

    public function pinTableColumn(string $columnName): void
    {
        if (! $this->hasTableColumn($columnName)) {
            return;
        }

        $this->tablePinnedColumns = array_values(array_unique([...$this->tablePinnedColumns, $columnName]));

        $this->applyTablePins();
    }

    public function unpinTableColumn(string $columnName): void
    {
        if (! $this->hasTableColumn($columnName)) {
            return;
        }

        $this->tablePinnedColumns = array_values(array_diff($this->tablePinnedColumns, [$columnName]));

        $this->applyTablePins();
    }

    public function editTableColumnLabelAction(): Action
    {
        return Action::make('editTableColumnLabel')
            ->label(__('filament/panel.tables.column_menu.edit_label'))
            ->modalHeading(__('filament/panel.tables.column_menu.edit_label'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.column_menu.save_label'))
            ->fillForm(fn (array $arguments): array => ['label' => $this->currentTableColumnLabel((string) ($arguments['column'] ?? ''))])
            ->schema([
                TextInput::make('label')
                    ->label(__('filament/panel.tables.column_menu.label'))
                    ->helperText(__('filament/panel.tables.column_menu.label_hint'))
                    ->maxLength(self::COLUMN_LABEL_MAX_LENGTH),
            ])
            ->action(fn (array $data, array $arguments) => $this->labelTableColumn((string) ($arguments['column'] ?? ''), trim((string) $data['label'])));
    }

    protected function tableColumnLabel(string $columnName, string $original): string
    {
        $this->originalTableColumnLabels[$columnName] = $original;

        return $this->tableColumnLabels[$columnName] ?? $original;
    }

    public function moveTableColumn(string $columnName, ?string $beforeColumnName): void
    {
        if (! $this->isMovableTableColumn($columnName, $beforeColumnName)) {
            return;
        }

        $columns = array_values(array_filter($this->tableColumns, fn (array $item): bool => $item['name'] !== $columnName));
        $moved = array_values(array_filter($this->tableColumns, fn (array $item): bool => $item['name'] === $columnName));
        $position = $beforeColumnName === null ? count($columns) : array_search($beforeColumnName, array_column($columns, 'name'), true);

        array_splice($columns, (int) $position, 0, $moved);

        $this->applyTableColumnManager($columns, wasReordered: true);
    }

    public function hideTableColumn(string $columnName): void
    {
        if (! $this->getTable()->getColumn($columnName)?->isToggleable()) {
            return;
        }

        $this->applyTableColumnManager(array_map(
            fn (array $item): array => $item['name'] === $columnName ? [...$item, 'isToggled' => false] : $item,
            $this->tableColumns,
        ), wasReordered: true);
    }

    public function toggleTableColumn(string $columnName): void
    {
        $item = collect($this->tableColumns)->first(fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE && $item['name'] === $columnName);

        if ($item === null || ! $this->getTable()->getColumn($columnName)?->isToggleable()) {
            return;
        }

        if ($item['isToggled']) {
            $this->hideTableColumn($columnName);

            return;
        }

        $others = array_values(array_filter($this->tableColumns, fn (array $other): bool => $other['name'] !== $columnName));

        $this->applyTableColumnManager([...$others, [...$item, 'isToggled' => true]], wasReordered: true);
    }

    /**
     * @return list<array{name: string, label: Htmlable, text: string, isShown: bool, isPinned: bool, isLocked: bool}>
     */
    protected function viewSettingsColumns(): array
    {
        $columns = [];

        foreach ($this->tableColumns as $item) {
            $column = $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE && ! $item['isHidden'] ? $this->getTable()->getColumn($item['name']) : null;

            if ($column === null) {
                continue;
            }

            $label = $column->getLabel();
            $label = $label instanceof Htmlable ? $label : new HtmlString(e((string) $label));

            $text = ColumnHeaderLabel::text($label);

            $columns[] = [
                'name' => $item['name'],
                'label' => $item['name'] === $this->getTableTitleColumn() ? ColumnHeaderLabel::make($text, FilterFieldCatalog::columnIcon($item['name'])) : $label,
                'text' => $text,
                'isShown' => $item['isToggled'],
                'isPinned' => in_array($item['name'], $this->tablePinnedColumns, true),
                'isLocked' => ! $column->isToggleable(),
            ];
        }

        return $columns;
    }

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        parent::applyTableColumnManager($state, $wasReordered);

        $this->tablePinnedColumns = $this->shownPinnedTableColumns();
        $columns = $this->arrangedTableColumns($this->tableColumns);

        if ($columns !== $this->tableColumns) {
            parent::applyTableColumnManager($columns, wasReordered: true);
        }
    }

    public function resetTableColumnManager(): void
    {
        $this->tablePinnedColumns = [];

        parent::resetTableColumnManager();

        $this->stampResizableColumns($this->getTable());
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    #[Unexposed]
    public function getDefaultTableColumnState(): array
    {
        $state = parent::getDefaultTableColumnState();

        // Filament reorders the table's columns in place, so a default rebuilt later in the request follows the member's order.
        $this->declaredTableColumnNames ??= array_column($state, 'name');
        $position = array_flip($this->declaredTableColumnNames);

        usort($state, fn (array $a, array $b): int => ($position[$a['name']] ?? PHP_INT_MAX) <=> ($position[$b['name']] ?? PHP_INT_MAX));

        return $state;
    }

    private function applyTablePins(): void
    {
        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);

        $this->stampResizableColumns($this->getTable());
    }

    private function currentTableColumnLabel(string $columnName): ?string
    {
        return $this->tableColumnLabels[$columnName] ?? $this->originalTableColumnLabels[$columnName] ?? null;
    }

    private function isMovableTableColumn(string $columnName, ?string $beforeColumnName): bool
    {
        if (! $this->hasTableColumn($columnName) || $columnName === $beforeColumnName || in_array($columnName, $this->tablePinnedColumns, true)) {
            return false;
        }

        return $beforeColumnName === null || $this->hasTableColumn($beforeColumnName);
    }

    private function hasTableColumn(string $columnName): bool
    {
        return collect($this->tableColumns)->contains(fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE && $item['name'] === $columnName);
    }

    private function labelTableColumn(string $columnName, string $label): void
    {
        if (! $this->hasTableColumn($columnName)) {
            return;
        }

        if ($label === '' || $label === ($this->originalTableColumnLabels[$columnName] ?? null)) {
            unset($this->tableColumnLabels[$columnName]);
        } else {
            $this->tableColumnLabels[$columnName] = $label;
        }

        $this->refreshTableColumnLabels();
    }

    // The column manager copies labels from a default state Filament caches per request.
    private function refreshTableColumnLabels(): void
    {
        $this->cachedDefaultTableColumnState = null;
        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);
    }

    /** @return list<string> */
    private function shownPinnedTableColumns(): array
    {
        return array_column(array_filter($this->tableColumns, fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE
            && $item['isToggled']
            && in_array($item['name'], $this->tablePinnedColumns, true)), 'name');
    }

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>  $columns
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function arrangedTableColumns(array $columns): array
    {
        $isPinned = fn (array $item): bool => in_array($item['name'], $this->tablePinnedColumns, true);

        usort($columns, fn (array $a, array $b): int => $isPinned($b) <=> $isPinned($a));

        return $columns;
    }
}

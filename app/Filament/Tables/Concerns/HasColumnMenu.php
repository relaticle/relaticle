<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Livewire\Attributes\Locked;

trait HasColumnMenu
{
    public const int COLUMN_LABEL_MAX_LENGTH = 60;

    /** @var array<string, string> */
    #[Locked]
    public array $tableColumnLabels = [];

    /** @var array<string, string> */
    private array $originalTableColumnLabels = [];

    /** @var list<string> */
    #[Locked]
    public array $tablePinnedColumns = [];

    /**
     * @return list<string>
     */
    public function getPinnedTableColumns(): array
    {
        return $this->tablePinnedColumns;
    }

    public function pinTableColumn(string $columnName): void
    {
        if (! $this->isMovableTableColumn($columnName)) {
            return;
        }

        $this->tablePinnedColumns = array_values(array_unique([$this->tableEntity()->titleColumn(), ...$this->tablePinnedColumns, $columnName]));

        $this->applyTablePins();
    }

    public function unpinTableColumn(string $columnName): void
    {
        if (! $this->isMovableTableColumn($columnName)) {
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
            ->fillForm(fn (array $arguments): array => ['label' => $this->tableColumnLabels[$arguments['column'] ?? ''] ?? $this->originalTableColumnLabels[$arguments['column'] ?? ''] ?? null])
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
        $names = array_column($this->movableTableColumns(), 'name');

        if (! in_array($columnName, $names, true) || $columnName === $beforeColumnName) {
            return;
        }

        if ($beforeColumnName !== null && $beforeColumnName !== $this->tableEntity()->titleColumn() && ! in_array($beforeColumnName, $names, true)) {
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

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        parent::applyTableColumnManager($state, $wasReordered);

        $columns = $this->arrangedTableColumns($this->tableColumns);

        if ($columns !== $this->tableColumns) {
            parent::applyTableColumnManager($columns, wasReordered: true);
        }
    }

    private function applyTablePins(): void
    {
        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);

        // The columns were stamped at boot, and this request renders them.
        $this->getTable()->resizableColumns();
    }

    private function isMovableTableColumn(string $columnName): bool
    {
        return in_array($columnName, array_column($this->movableTableColumns(), 'name'), true);
    }

    private function labelTableColumn(string $columnName, string $label): void
    {
        if (! in_array($columnName, array_column($this->movableTableColumns(), 'name'), true)) {
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

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function movableTableColumns(): array
    {
        $title = $this->tableEntity()->titleColumn();

        return array_filter($this->tableColumns, fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE && $item['name'] !== $title);
    }

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>  $columns
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function arrangedTableColumns(array $columns): array
    {
        $title = $this->tableEntity()->titleColumn();

        $pinned = array_column(array_filter($columns, fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE
            && $item['name'] !== $title
            && $item['isToggled']
            && in_array($item['name'], $this->tablePinnedColumns, true)), 'name');

        // The title column is pinned only beside another pinned column.
        $this->tablePinnedColumns = $pinned === [] ? [] : [$title, ...$pinned];

        $rank = fn (array $item): int => match (true) {
            $item['name'] === $title => 0,
            in_array($item['name'], $this->tablePinnedColumns, true) => 1,
            default => 2,
        };

        usort($columns, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $columns;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Enums\ColumnMenuState;
use App\Filament\Tables\Actions\ColumnMenuAction;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
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

    /** @return array<int, array<int, ColumnMenuAction>> */
    public function columnMenuActions(): array
    {
        return [
            [
                ColumnMenuAction::make('moveLeft')
                    ->label(__('filament/panel.tables.column_menu.move_left'))
                    ->icon(Heroicon::ArrowLeft)
                    ->callsWire('moveTableColumn', ColumnMenuState::LeftTarget)
                    ->visibleWhen(ColumnMenuState::Movable)
                    ->enabledWhen(ColumnMenuState::CanMoveLeft)
                    ->refocus(ColumnMenuState::Column),
                ColumnMenuAction::make('moveRight')
                    ->label(__('filament/panel.tables.column_menu.move_right'))
                    ->icon(Heroicon::ArrowRight)
                    ->callsWire('moveTableColumn', ColumnMenuState::RightTarget)
                    ->visibleWhen(ColumnMenuState::Movable)
                    ->enabledWhen(ColumnMenuState::CanMoveRight)
                    ->refocus(ColumnMenuState::Column),
            ],
            [
                ColumnMenuAction::make('pin')
                    ->label(__('filament/panel.tables.column_menu.pin'))
                    ->icon(Heroicon::LockClosed)
                    ->callsWire('pinTableColumn')
                    ->hiddenWhen(ColumnMenuState::Pinned)
                    ->refocus(ColumnMenuState::Column),
                ColumnMenuAction::make('unpin')
                    ->label(__('filament/panel.tables.column_menu.unpin'))
                    ->icon(Heroicon::LockOpen)
                    ->callsWire('unpinTableColumn')
                    ->visibleWhen(ColumnMenuState::Pinned)
                    ->refocus(ColumnMenuState::Column),
            ],
            [
                ColumnMenuAction::make('editLabel')
                    ->label(__('filament/panel.tables.column_menu.edit_label'))
                    ->icon(Heroicon::OutlinedTag)
                    ->mountsAction('editTableColumnLabel'),
                ColumnMenuAction::make('hide')
                    ->label(__('filament/panel.tables.column_menu.hide'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->callsWire('hideTableColumn')
                    ->visibleWhen(ColumnMenuState::Hideable)
                    ->refocus(ColumnMenuState::Neighbour),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function getPinnedTableColumns(): array
    {
        return $this->tablePinnedColumns;
    }

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
        if (! $this->hasTableColumn($columnName) || $columnName === $beforeColumnName || in_array($columnName, $this->tablePinnedColumns, true)) {
            return;
        }

        if ($beforeColumnName !== null && ! $this->hasTableColumn($beforeColumnName)) {
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

        // The pins were stamped at boot, and this request renders none.
        $this->getTable()->resizableColumns();
    }

    private function applyTablePins(): void
    {
        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);

        // The columns were stamped at boot, and this request renders them.
        $this->getTable()->resizableColumns();
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

<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Filament\Tables\ResizableColumns;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

trait HasResizableColumns
{
    /** @var array<string, int> */
    #[Locked]
    public array $tableColumnWidths = [];

    #[Renderless]
    public function updateResizableColumnWidth(string $columnName, string $newWidth): void
    {
        $width = (int) $newWidth;

        if ($width <= 0 || $this->getTable()->getColumn($columnName) === null) {
            return;
        }

        $width = max(ResizableColumns::MIN_WIDTH, min(ResizableColumns::MAX_WIDTH, $width));

        $this->tableColumnWidths[$columnName] = $width;

        $this->stampResizableColumns($this->getTable());
    }

    protected function stampResizableColumns(Table $table): Table
    {
        // Columns are stamped as the table builds, so a request that changes widths or pins stamps again before it renders.
        return ResizableColumns::apply($table, $this->tableColumnWidths, $this->tablePinnedColumns, $this->getTableTitleColumn());
    }
}

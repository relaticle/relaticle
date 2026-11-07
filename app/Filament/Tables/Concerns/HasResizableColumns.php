<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Filament\Tables\ResizableColumns;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

trait HasResizableColumns
{
    /** @var array<string, int> */
    #[Locked]
    public array $tableColumnWidths = [];

    /**
     * @return array<string, int>
     */
    public function getResizableColumnWidths(): array
    {
        return $this->tableColumnWidths;
    }

    #[Renderless]
    public function updateResizableColumnWidth(string $columnName, string $newWidth): void
    {
        $width = (int) $newWidth;

        if ($width <= 0 || $this->getTable()->getColumn($columnName) === null) {
            return;
        }

        $width = max(ResizableColumns::MIN_WIDTH, min(ResizableColumns::MAX_WIDTH, $width));

        $this->tableColumnWidths[$columnName] = $width;

        // The columns were stamped at boot, and a sort in the same request renders them.
        $this->getTable()->resizableColumns();
    }
}

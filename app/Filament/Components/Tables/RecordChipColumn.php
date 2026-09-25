<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Filament\Components\RecordChip;
use App\Filament\Concerns\HasRecordChips;
use Filament\Tables\Columns\TextColumn;

final class RecordChipColumn extends TextColumn
{
    use HasRecordChips;

    protected string $view = 'filament.tables.columns.record-chip-column';

    /**
     * @return array<int, RecordChip>
     */
    public function getChips(): array
    {
        return $this->recordChips($this->getRecord(), $this->getName());
    }
}

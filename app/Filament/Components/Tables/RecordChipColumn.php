<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Components\RecordChip;
use App\Filament\Concerns\HasRecordChips;
use Filament\Tables\Columns\TextColumn;

final class RecordChipColumn extends TextColumn
{
    use HasRecordChips;

    protected string $view = 'filament.tables.columns.record-chip-column';

    private bool $isPreviewable = false;

    public function previewable(): static
    {
        $this->isPreviewable = true;

        return $this;
    }

    public function collapsed(): static
    {
        return $this
            ->view('filament.tables.columns.record-chip-overflow-column')
            ->disabledClick();
    }

    /**
     * @return array<int, RecordChip>
     */
    public function getChips(): array
    {
        return $this->recordChips($this->getRecord(), $this->getName());
    }

    public function getPreviewActionName(): ?string
    {
        if (! $this->isPreviewable) {
            return null;
        }

        $action = $this->getTable()->getAction(PreviewRecordAction::getDefaultName());

        if (! $action instanceof PreviewRecordAction) {
            return null;
        }

        $action->record($this->getRecord());

        if ($action->isHidden()) {
            return null;
        }

        return $action->getName();
    }
}

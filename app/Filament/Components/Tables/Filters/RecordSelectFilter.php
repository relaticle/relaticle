<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables\Filters;

use App\Filament\Concerns\HasRecordChips;
use App\Filament\Concerns\SelectsRecordChips;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;

final class RecordSelectFilter extends SelectFilter
{
    use HasRecordChips;
    use SelectsRecordChips;

    public function getFormField(): Select
    {
        return parent::getFormField()->allowHtml();
    }

    public function relationship(
        string|Closure|null $name,
        string|Closure|null $titleAttribute,
        ?Closure $modifyQueryUsing = null,
        bool|Closure $hasEmptyOption = false,
    ): static {
        return parent::relationship(
            $name,
            $titleAttribute,
            $this->recordChipQuery($modifyQueryUsing),
            $hasEmptyOption,
        );
    }

    protected function recordChipTitleAttribute(): ?string
    {
        return $this->getRelationshipTitleAttribute();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->getOptionLabelFromRecordUsing(fn (Model $record): string => $this->recordChipLabel($record));
    }
}

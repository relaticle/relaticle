<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Filament\Concerns\HasRecordChips;
use App\Filament\Concerns\SelectsRecordChips;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * A relationship picker whose options carry the record's avatar.
 *
 * Filament's select has no option-image slot, so the label is raw HTML. Search
 * stays server-side because a relationship title attribute fills
 * getSearchColumns(), which keeps the markup out of the client-side filter.
 */
final class RecordSelect extends Select
{
    use HasRecordChips;
    use SelectsRecordChips;

    public function relationship(
        string|Closure|null $name = null,
        string|Closure|null $titleAttribute = null,
        ?Closure $modifyQueryUsing = null,
        bool $ignoreRecord = false,
    ): static {
        return parent::relationship(
            $name,
            $titleAttribute,
            $this->recordChipQuery($modifyQueryUsing),
            $ignoreRecord,
        );
    }

    protected function recordChipTitleAttribute(): ?string
    {
        return $this->getRelationshipTitleAttribute();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowHtml();
        $this->getOptionLabelFromRecordUsing(fn (Model $record): string => $this->recordChipLabel($record));
    }
}

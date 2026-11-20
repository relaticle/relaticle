<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use Closure;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Lang;

final class EmptyListDescription
{
    /**
     * @return Closure(HasTable): ?string
     */
    public static function unlessFiltered(string $description): Closure
    {
        return fn (HasTable $livewire): ?string => match (true) {
            FilteredEmptyState::isFilteredByBar($livewire) => Lang::string('filament/panel.tables.filters.empty_description'),
            $livewire->hasTableSearch() || $livewire->getTable()->isFiltered() => null,
            default => $description,
        };
    }
}

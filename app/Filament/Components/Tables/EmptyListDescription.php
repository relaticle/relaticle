<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use Closure;
use Filament\Tables\Contracts\HasTable;

final class EmptyListDescription
{
    /**
     * @return Closure(HasTable): ?string
     */
    public static function unlessFiltered(string $description): Closure
    {
        return fn (HasTable $livewire): ?string => $livewire->hasTableSearch() || $livewire->getTable()->isFiltered() || self::hasFilterBarState($livewire)
            ? null
            : $description;
    }

    private static function hasFilterBarState(HasTable $livewire): bool
    {
        return method_exists($livewire, 'hasFilterBarState') && $livewire->hasFilterBarState();
    }
}

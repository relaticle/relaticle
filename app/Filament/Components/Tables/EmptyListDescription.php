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
        return fn (HasTable $livewire): ?string => $livewire->hasTableSearch() || $livewire->getTable()->isFiltered()
            ? null
            : $description;
    }
}

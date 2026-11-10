<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use Closure;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final class FilteredEmptyState
{
    /**
     * @return Closure(HasTable, Table): ?string
     */
    public static function heading(): Closure
    {
        return fn (HasTable $livewire, Table $table): ?string => self::isFilteredByBar($livewire)
            ? Lang::string('filament/panel.tables.filters.empty_heading', ['records' => Str::lower($table->getPluralModelLabel())])
            : null;
    }

    public static function clearAction(): Action
    {
        return Action::make('clearFilterBar')
            ->label(__('filament/panel.tables.filters.clear'))
            ->color('gray')
            ->visible(fn (HasTable $livewire): bool => self::isFilteredByBar($livewire))
            ->action(function (HasTable $livewire): void {
                if (method_exists($livewire, 'clearFilterBar')) {
                    $livewire->clearFilterBar();
                }
            });
    }

    private static function isFilteredByBar(HasTable $livewire): bool
    {
        return method_exists($livewire, 'hasFilterBarState') && $livewire->hasFilterBarState();
    }
}

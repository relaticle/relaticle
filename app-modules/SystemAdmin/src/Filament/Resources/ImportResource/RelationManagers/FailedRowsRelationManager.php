<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ImportResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class FailedRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'failedRows';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedExclamationTriangle;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->failedRows()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('data')
                    ->label('Row Data')
                    ->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->wrap()
                    ->limit(100),
                TextColumn::make('validation_error')
                    ->label('Validation Error')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}

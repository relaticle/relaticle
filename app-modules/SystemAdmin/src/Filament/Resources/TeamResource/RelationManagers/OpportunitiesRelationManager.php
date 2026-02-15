<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OpportunitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'opportunities';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-currency-dollar';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

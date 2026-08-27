<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class ImportsRelationManager extends RelationManager
{
    protected static string $relationship = 'imports';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-arrow-up-tray';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->imports()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_name')
            ->columns([
                TextColumn::make('file_name')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (Import $record): string => ImportResource::getUrl('view', ['record' => $record])),
                TextColumn::make('entity_type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(ImportResource::statusColor(...)),
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'user')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

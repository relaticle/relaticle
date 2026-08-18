<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Models\People;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;

final class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    protected static ?string $modelLabel = 'person';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->people()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (People $record): string => PeopleResource::getUrl('view', ['record' => $record])),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->url(fn (People $record): ?string => $record->company_id === null ? null : CompanyResource::getUrl('view', ['record' => $record->company_id])),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->url(fn (People $record): ?string => $record->creator_id === null ? null : UserResource::getUrl('view', ['record' => $record->creator_id])),
                TextColumn::make('creation_source')
                    ->badge()
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

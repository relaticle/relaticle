<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Models\Company;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-building-office';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->companies()->count();

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
                    ->color('primary')
                    ->url(fn (Company $record): string => CompanyResource::getUrl('view', ['record' => $record])),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable()
                    ->color('primary')
                    ->url(RecordLink::to(UserResource::class, 'creator')),
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

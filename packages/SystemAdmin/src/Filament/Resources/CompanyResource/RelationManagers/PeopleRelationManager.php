<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\CompanyResource\RelationManagers;

use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\RecordLink;

final class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->people()->withoutGlobalScope(WorkspaceScope::class)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(WorkspaceScope::class))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (People $record): string => PeopleResource::getUrl('view', ['record' => $record])),
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

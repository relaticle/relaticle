<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;

final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-users';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->users()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => PivotSafeTableQuery::apply($query, $this->getRelationship()))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (User $record): string => UserResource::getUrl('view', ['record' => $record])),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('membership.created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\RelationManagers;

use App\Models\Team;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;

final class OwnedTeamsRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedTeams';

    protected static ?string $title = 'Owned Teams';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-building-office-2';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->ownedTeams()->count();

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
                    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),
                IconColumn::make('personal_team')
                    ->label('Personal')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}

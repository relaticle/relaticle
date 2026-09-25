<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\RelationManagers;

use App\Models\Workspace;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;

final class OwnedWorkspacesRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedWorkspaces';

    protected static ?string $title = 'Owned Workspaces';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-building-office-2';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->ownedWorkspaces()->count();

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
                    ->url(fn (Workspace $record): string => WorkspaceResource::getUrl('view', ['record' => $record])),
                IconColumn::make('personal_workspace')
                    ->label('Personal')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}

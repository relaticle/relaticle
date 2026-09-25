<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\UserResource\RelationManagers;

use App\Models\Workspace;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;

final class WorkspacesRelationManager extends RelationManager
{
    protected static string $relationship = 'workspaces';

    protected static ?string $title = 'Member Of';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user-group';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->workspaces()->count();

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
                    ->url(fn (Workspace $record): string => WorkspaceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('membership.role')
                    ->label('Role')
                    ->badge()
                    ->sortable(),
                TextColumn::make('membership.created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}

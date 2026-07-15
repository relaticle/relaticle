<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources;

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;

final class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Dashboards';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?string $slug = 'activity';

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(TeamScope::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('User')
                    ->placeholder('System')
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ucfirst($state)),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->limit(60)
                    ->wrap(),
            ]);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}

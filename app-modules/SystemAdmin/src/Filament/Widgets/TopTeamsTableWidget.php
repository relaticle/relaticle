<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;

final class TopTeamsTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Teams';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string> */
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Team')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->sortable()
                    ->url(fn (Team $record): string => UserResource::getUrl('view', ['record' => $record->owner])),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('records_count')
                    ->label('Records')
                    ->numeric()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        [$sql, $bindings] = $this->buildRecordsCountExpression(
                            CreationSource::SYSTEM->value,
                            ...array_values($this->getDateRange()),
                        );

                        return $query->orderByRaw("({$sql}) {$direction}", $bindings);
                    })
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                TextColumn::make('custom_fields_count')
                    ->label('Custom Fields')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('warning'),

                TextColumn::make('last_activity')
                    ->label('Last Activity')
                    ->since()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('records_count', 'desc')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->emptyStateHeading('No Active Teams')
            ->emptyStateDescription('Team activity will appear here once teams start creating records.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    /**
     * @return array{string, string}
     */
    private function getDateRange(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }

    private function buildQuery(): Builder
    {
        $systemSource = CreationSource::SYSTEM->value;
        [$startStr, $endStr] = $this->getDateRange();

        [$recordsCountSql, $recordsBindings] = $this->buildRecordsCountExpression($systemSource, $startStr, $endStr);
        [$lastActivitySql, $lastActivityBindings] = $this->buildLastActivityExpression($systemSource);

        return Team::query()
            ->where('personal_team', false)
            ->select(['teams.*'])
            ->selectRaw("({$recordsCountSql}) as records_count", $recordsBindings)
            ->selectRaw('(SELECT COUNT(*) FROM team_user WHERE team_user.team_id = teams.id) as members_count')
            ->selectRaw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count')
            ->selectRaw("{$lastActivitySql} as last_activity", $lastActivityBindings)
            ->whereRaw("({$recordsCountSql}) > 0", $recordsBindings);
    }

    /**
     * @return array{string, array<int, string>}
     */
    private function buildRecordsCountExpression(string $systemSource, string $startStr, string $endStr): array
    {
        $subqueries = collect(self::ENTITY_TABLES)->map(
            fn (string $table): string => "(SELECT COUNT(*) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.deleted_at IS NULL AND {$table}.creation_source != ? AND {$table}.created_at BETWEEN ? AND ?)"
        );

        $bindings = collect(self::ENTITY_TABLES)
            ->flatMap(fn (): array => [$systemSource, $startStr, $endStr])
            ->all();

        return [$subqueries->implode(' + '), $bindings];
    }

    /**
     * @return array{string, array<int, string>}
     */
    private function buildLastActivityExpression(string $systemSource): array
    {
        $coalesces = collect(self::ENTITY_TABLES)->map(
            fn (string $table): string => "COALESCE((SELECT MAX(created_at) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.creation_source != ?), TIMESTAMP '1970-01-01')"
        );

        $bindings = collect(self::ENTITY_TABLES)
            ->map(fn (): string => $systemSource)
            ->all();

        return ["GREATEST({$coalesces->implode(', ')})", $bindings];
    }
}

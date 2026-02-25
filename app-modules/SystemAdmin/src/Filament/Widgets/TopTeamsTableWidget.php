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
use Illuminate\Database\Query\Builder as QueryBuilder;
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
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('records_count', $direction))
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
        return once(function (): array {
            $days = (int) ($this->pageFilters['period'] ?? 30);
            $end = CarbonImmutable::now();
            $start = $end->subDays($days);

            return [$start->toDateTimeString(), $end->toDateTimeString()];
        });
    }

    /**
     * @return array{string, array<int, string>}
     */
    private function getRecordsCountExpression(): array
    {
        return once(function (): array {
            [$startStr, $endStr] = $this->getDateRange();

            return $this->buildRecordsCountExpression(CreationSource::SYSTEM->value, $startStr, $endStr);
        });
    }

    private function buildQuery(): Builder
    {
        [$recordsCountSql, $recordsBindings] = $this->getRecordsCountExpression();
        [$lastActivitySql, $lastActivityBindings] = $this->buildLastActivityExpression(CreationSource::SYSTEM->value);
        [$startStr, $endStr] = $this->getDateRange();
        $systemSource = CreationSource::SYSTEM->value;

        return Team::query()
            ->select(['teams.*'])
            ->selectRaw("({$recordsCountSql}) as records_count", $recordsBindings)
            ->selectRaw('(SELECT COUNT(*) FROM team_user WHERE team_user.team_id = teams.id) as members_count')
            ->selectRaw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count')
            ->selectRaw("{$lastActivitySql} as last_activity", $lastActivityBindings)
            ->where(function (Builder $query) use ($startStr, $endStr, $systemSource): void {
                foreach (self::ENTITY_TABLES as $table) {
                    $query->orWhereExists(function (QueryBuilder $sub) use ($table, $startStr, $endStr, $systemSource): void {
                        $sub->selectRaw('1')
                            ->from($table)
                            ->whereColumn("{$table}.team_id", 'teams.id')
                            ->whereNull("{$table}.deleted_at")
                            ->where("{$table}.creation_source", '!=', $systemSource)
                            ->whereBetween("{$table}.created_at", [$startStr, $endStr]);
                    });
                }
            });
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
            fn (string $table): string => "COALESCE((SELECT MAX(created_at) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.deleted_at IS NULL AND {$table}.creation_source != ?), TIMESTAMP '1970-01-01')"
        );

        $bindings = array_fill(0, count(self::ENTITY_TABLES), $systemSource);

        return ["GREATEST({$coalesces->implode(', ')})", $bindings];
    }
}

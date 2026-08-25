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

/**
 * Teams that created records before the selected period but none inside it:
 * the churn-risk list worth a personal follow-up at the current scale.
 */
final class GoneQuietTeamsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Gone Quiet';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string> */
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->description('Teams active before this period with no activity in it.')
            ->columns([
                TextColumn::make('name')
                    ->label('Team')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->color('primary')
                    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->sortable()
                    ->color('primary')
                    ->url(fn (Team $record): ?string => $record->owner ? UserResource::getUrl('view', ['record' => $record->owner]) : null),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('last_activity')
                    ->label('Last Activity')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_activity', 'desc')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->emptyStateHeading('No teams have gone quiet')
            ->emptyStateDescription('Every previously active team also has activity in this period.')
            ->emptyStateIcon('heroicon-o-moon');
    }

    private function buildQuery(): Builder
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);
        $startStr = $start->toDateTimeString();
        $endStr = $end->toDateTimeString();
        $systemSource = CreationSource::SYSTEM->value;

        [$lastActivitySql, $lastActivityBindings] = $this->buildLastActivityExpression($systemSource);

        return Team::query()
            ->select(['teams.*'])
            // Jetstream keeps the owner out of team_user, hence the +1.
            ->selectRaw('(SELECT COUNT(*) + 1 FROM team_user WHERE team_user.team_id = teams.id) as members_count')
            ->selectRaw("{$lastActivitySql} as last_activity", $lastActivityBindings)
            ->where(function (Builder $query) use ($startStr, $systemSource): void {
                foreach (self::ENTITY_TABLES as $table) {
                    $query->orWhereExists(function (QueryBuilder $sub) use ($table, $startStr, $systemSource): void {
                        $sub->selectRaw('1')
                            ->from($table)
                            ->whereColumn("{$table}.team_id", 'teams.id')
                            ->whereNull("{$table}.deleted_at")
                            ->where("{$table}.creation_source", '!=', $systemSource)
                            ->where("{$table}.created_at", '<', $startStr);
                    });
                }
            })
            ->whereNot(function (Builder $query) use ($startStr, $endStr, $systemSource): void {
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
    private function buildLastActivityExpression(string $systemSource): array
    {
        $coalesces = collect(self::ENTITY_TABLES)->map(
            fn (string $table): string => "COALESCE((SELECT MAX(created_at) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.deleted_at IS NULL AND {$table}.creation_source != ?), TIMESTAMP '1970-01-01')"
        );

        $bindings = array_fill(0, count(self::ENTITY_TABLES), $systemSource);

        return ["GREATEST({$coalesces->implode(', ')})", $bindings];
    }
}

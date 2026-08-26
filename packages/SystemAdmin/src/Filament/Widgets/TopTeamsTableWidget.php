<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;

/**
 * Ranks teams by the distinct records they worked on in the selected period,
 * read from the activity log so edits and deletes count as activity, not only
 * record creation. Seeded demo data never appears here: OnboardSeed runs
 * inside Model::withoutEvents(), so it writes no activity rows.
 */
final class TopTeamsTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Teams';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

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
                    ->color('primary')
                    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->sortable()
                    ->color('primary')
                    ->url(fn (Team $record): ?string => $record->owner ? UserResource::getUrl('view', ['record' => $record->owner]) : null),

                TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn (Plan $state): string => $state->label())
                    ->color(fn (Plan $state): string => match ($state) {
                        Plan::Free => 'gray',
                        Plan::Pro => 'success',
                        Plan::Enterprise => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->state(fn (Team $record): string => "{$record->active_members} / {$record->members_count}")
                    ->tooltip('Active in period / total members')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('records_touched')
                    ->label('Records')
                    ->tooltip('Distinct records with activity in the period')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                TextColumn::make('active_days')
                    ->label('Active Days')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('success'),

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
            ->recordActions([
                Action::make('activity')
                    ->label('Activity')
                    ->icon('heroicon-o-clock')
                    ->url(fn (Team $record): string => ActivityResource::getUrl('index', [
                        'filters' => ['team_id' => ['value' => $record->id]],
                    ])),
            ])
            ->defaultSort('records_touched', 'desc')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->striped()
            ->emptyStateHeading('No Active Teams')
            ->emptyStateDescription('Team activity will appear here once teams start working with records.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    private function buildQuery(): Builder
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $userMorphAlias = (new User)->getMorphClass();

        $activity = DB::table('activity_log')
            ->selectRaw(<<<'SQL'
                team_id,
                COUNT(DISTINCT (subject_type, subject_id)) AS records_touched,
                COUNT(DISTINCT created_at::date) AS active_days,
                COUNT(DISTINCT causer_id) FILTER (WHERE causer_type = ?) AS active_members,
                MAX(created_at) AS last_activity
            SQL, [$userMorphAlias])
            ->whereNotNull('team_id')
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy('team_id');

        return Team::query()
            ->select([
                'teams.*',
                'activity.records_touched',
                'activity.active_days',
                'activity.active_members',
                'activity.last_activity',
            ])
            // Jetstream keeps the owner out of team_user, hence the +1.
            ->selectRaw('(SELECT COUNT(*) + 1 FROM team_user WHERE team_user.team_id = teams.id) as members_count')
            ->selectRaw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count')
            ->joinSub($activity, 'activity', 'activity.team_id', '=', 'teams.id');
    }
}

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
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;

final class TopTeamsTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Teams';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    private ?string $pollingInterval = null;

    /** @var array<int, string> */
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    public function table(Table $table): Table
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);
        $systemSource = CreationSource::SYSTEM->value;
        $startStr = $start->toDateTimeString();
        $endStr = $end->toDateTimeString();

        return $table
            ->query(
                Team::query()
                    ->where('personal_team', false)
                    ->addSelect([
                        'teams.*',
                        $this->buildRecordsCountSelect($systemSource, $startStr, $endStr),
                        DB::raw('(SELECT COUNT(*) FROM team_user WHERE team_user.team_id = teams.id) as members_count'),
                        DB::raw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count'),
                        $this->buildLastActivitySelect($systemSource),
                    ])
                    ->having('records_count', '>', 0)
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Team')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->sortable(),

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
                    ->sortable()
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

    private function buildRecordsCountSelect(string $systemSource, string $startStr, string $endStr): Expression
    {
        $subqueries = collect(self::ENTITY_TABLES)->map(
            fn (string $table): string => "(SELECT COUNT(*) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.deleted_at IS NULL AND {$table}.creation_source != '{$systemSource}' AND {$table}.created_at BETWEEN '{$startStr}' AND '{$endStr}')"
        );

        return DB::raw("({$subqueries->implode(' + ')}) as records_count");
    }

    private function buildLastActivitySelect(string $systemSource): Expression
    {
        $coalesces = collect(self::ENTITY_TABLES)->map(
            fn (string $table): string => "COALESCE((SELECT MAX(created_at) FROM {$table} WHERE {$table}.team_id = teams.id AND {$table}.creation_source != '{$systemSource}'), '1970-01-01')"
        );

        return DB::raw("GREATEST({$coalesces->implode(', ')}) as last_activity");
    }
}

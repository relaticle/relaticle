<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

final class TopTeamsTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Teams';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

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
                        DB::raw("(
                            (SELECT COUNT(*) FROM companies WHERE companies.team_id = teams.id AND companies.deleted_at IS NULL AND companies.creation_source != '{$systemSource}' AND companies.created_at BETWEEN '{$startStr}' AND '{$endStr}')
                            + (SELECT COUNT(*) FROM people WHERE people.team_id = teams.id AND people.deleted_at IS NULL AND people.creation_source != '{$systemSource}' AND people.created_at BETWEEN '{$startStr}' AND '{$endStr}')
                            + (SELECT COUNT(*) FROM tasks WHERE tasks.team_id = teams.id AND tasks.deleted_at IS NULL AND tasks.creation_source != '{$systemSource}' AND tasks.created_at BETWEEN '{$startStr}' AND '{$endStr}')
                            + (SELECT COUNT(*) FROM notes WHERE notes.team_id = teams.id AND notes.deleted_at IS NULL AND notes.creation_source != '{$systemSource}' AND notes.created_at BETWEEN '{$startStr}' AND '{$endStr}')
                            + (SELECT COUNT(*) FROM opportunities WHERE opportunities.team_id = teams.id AND opportunities.deleted_at IS NULL AND opportunities.creation_source != '{$systemSource}' AND opportunities.created_at BETWEEN '{$startStr}' AND '{$endStr}')
                        ) as records_count"),
                        DB::raw('(SELECT COUNT(*) FROM team_user WHERE team_user.team_id = teams.id) as members_count'),
                        DB::raw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count'),
                        DB::raw("GREATEST(
                            COALESCE((SELECT MAX(created_at) FROM companies WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM people WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM tasks WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM notes WHERE notes.team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM opportunities WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01')
                        ) as last_activity"),
                    ])
                    ->having('records_count', '>', 0)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Team')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Owner')
                    ->sortable(),

                Tables\Columns\TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('records_count')
                    ->label('Records')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('custom_fields_count')
                    ->label('Custom Fields')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('Last Activity')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
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
}

<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserRetentionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    public function getHeading(): string
    {
        return 'User Retention';
    }

    public function getDescription(): string
    {
        return 'New active vs returning users per week.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $intervals = $this->buildWeeklyIntervals($start, $end);

        $newActive = [];
        $returning = [];
        $labels = [];

        foreach ($intervals as $interval) {
            $labels[] = $interval['label'];

            $activeCreators = $this->getActiveCreators($interval['start'], $interval['end']);

            if ($activeCreators->isEmpty()) {
                $newActive[] = 0;
                $returning[] = 0;

                continue;
            }

            $newCount = DB::table('users')
                ->whereIn('id', $activeCreators)
                ->whereBetween('created_at', [$interval['start'], $interval['end']])
                ->count();

            $returningCount = DB::table('users')
                ->whereIn('id', $activeCreators)
                ->where('created_at', '<', $interval['start'])
                ->count();

            $newActive[] = $newCount;
            $returning[] = $returningCount;
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Active',
                    'data' => $newActive,
                    'backgroundColor' => 'rgba(99, 102, 241, 0.8)',
                    'borderColor' => '#6366f1',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Returning',
                    'data' => $returning,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                    'borderColor' => '#10b981',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function getActiveCreators(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_TABLES as $table) {
            $unionParts[] = "SELECT DISTINCT \"creator_id\" FROM \"{$table}\" WHERE \"creator_id\" IS NOT NULL AND \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $sql = 'SELECT DISTINCT creator_id FROM ('.implode(' UNION ', $unionParts).') AS all_creators';

        return collect(DB::select($sql, $bindings))->pluck('creator_id');
    }

    /**
     * @return Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildWeeklyIntervals(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $intervals = collect();
        $current = $start->startOfWeek();

        while ($current->lt($end)) {
            $weekEnd = $current->endOfWeek()->min($end);
            $intervals->push([
                'label' => $current->format('M j'),
                'start' => $current,
                'end' => $weekEnd,
            ]);
            $current = $current->addWeek();
        }

        return $intervals;
    }
}

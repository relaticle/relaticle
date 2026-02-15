<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

final class RecordDistributionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    /**
     * @return array<string, mixed>
     */
    public function getColumnSpan(): array
    {
        return [
            'default' => 'full',
            'lg' => 1,
        ];
    }

    public function getHeading(): string
    {
        return 'Records by Type';
    }

    public function getDescription(): string
    {
        return 'Distribution of new records in this period.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $entities = [
            'Companies' => Company::class,
            'People' => People::class,
            'Tasks' => Task::class,
            'Notes' => Note::class,
            'Opportunities' => Opportunity::class,
        ];

        $counts = [];
        $labels = [];

        foreach ($entities as $label => $class) {
            $count = $class::query()
                ->where('creation_source', '!=', CreationSource::SYSTEM)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $labels[] = $label;
            $counts[] = $count;
        }

        return [
            'datasets' => [
                [
                    'data' => $counts,
                    'backgroundColor' => [
                        '#6366f1',
                        '#8b5cf6',
                        '#10b981',
                        '#f59e0b',
                        '#3b82f6',
                    ],
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}

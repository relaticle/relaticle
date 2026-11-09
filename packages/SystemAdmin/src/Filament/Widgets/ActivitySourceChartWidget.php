<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Illuminate\Support\Collection;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;

final class ActivitySourceChartWidget extends ChartWidget
{
    use InteractsWithPageTable;

    private const string UNRECORDED_COLOR = '#8a8a85';

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Activity by source';

    protected ?string $description = 'Counts follow the table filters.';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListActivities::class;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $counts = $this->countsBySource();
        $total = max(1, $counts->sum());

        $slices = collect(CreationSource::cases())
            ->filter(fn (CreationSource $source): bool => $counts->get($source->value, 0) > 0)
            ->map(fn (CreationSource $source): array => [
                'label' => $source->getLabel(),
                'count' => $counts->get($source->value),
                'color' => $source->chartColor(),
            ])
            ->when($counts->get('', 0) > 0, fn (Collection $slices): Collection => $slices->push([
                'label' => 'Not recorded',
                'count' => $counts->get(''),
                'color' => self::UNRECORDED_COLOR,
            ]))
            ->values();

        return [
            'datasets' => [[
                'data' => $slices->pluck('count')->all(),
                'backgroundColor' => $slices->pluck('color')->all(),
                'borderWidth' => 0,
                'spacing' => 2,
            ]],
            'labels' => $slices
                ->map(fn (array $slice): string => sprintf('%s: %s (%d%%)', $slice['label'], number_format($slice['count']), round($slice['count'] / $total * 100)))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'cutout' => '60%',
            'plugins' => [
                'legend' => ['position' => 'right'],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function countsBySource(): Collection
    {
        return $this->getPageTableQuery()
            ->reorder()
            ->toBase()
            ->selectRaw("coalesce(activity_log.properties->>'source', '') as source, count(*) as total")
            ->groupByRaw("coalesce(activity_log.properties->>'source', '')")
            ->pluck('total', 'source')
            ->map(fn (mixed $total): int => (int) $total);
    }
}

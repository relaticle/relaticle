<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Concerns;

use App\Enums\CreationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared logic for dashboard widgets that compare metrics across time periods.
 *
 * Provides period calculation, percentage change formatting, and PostgreSQL
 * bucket-based sparkline generation for StatsOverviewWidget subclasses.
 */
trait HasPeriodComparison
{
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function getPeriodDates(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $currentEnd = CarbonImmutable::now();
        $currentStart = $currentEnd->subDays($days);
        $previousEnd = $currentStart->subSecond();
        $previousStart = $currentStart->subDays($days);

        return [$currentStart, $currentEnd, $previousStart, $previousEnd];
    }

    private function calculateChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function formatChange(float $change): string
    {
        if ($change === 0.0) {
            return '';
        }

        $sign = $change > 0 ? '+' : '';

        return " ({$sign}{$change}%)";
    }

    /**
     * PostgreSQL expression that assigns rows to time-based buckets.
     *
     * Expects two bindings: the period start timestamp and the segment duration in seconds.
     */
    private function bucketExpression(): string
    {
        return 'FLOOR(EXTRACT(EPOCH FROM ("created_at" - ?::timestamp)) / ?)';
    }

    /**
     * @return array<int, int>
     */
    private function fillBuckets(Collection $rows, int $points): array
    {
        $buckets = array_fill(0, $points, 0);

        foreach ($rows as $row) {
            $idx = min((int) $row->bucket, $points - 1);

            if ($idx >= 0) {
                $buckets[$idx] += (int) $row->cnt;
            }
        }

        return $buckets;
    }

    /**
     * @return Collection<int, int|string>
     */
    private function getActiveCreatorIds(CarbonImmutable $start, CarbonImmutable $end): Collection
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
}

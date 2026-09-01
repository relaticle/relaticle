<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Concerns;

use App\Enums\CreationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;

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
     * The current window is the viewer's last $days calendar days, ending with
     * today so far; the comparison window is that same window shifted back
     * $days days, so both end at the same wall clock. Across a DST transition
     * the two spans differ by the hour the clocks moved, which is the price of
     * comparing like time of day rather than like duration.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function getPeriodDates(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);

        [$currentStart, $currentEnd] = ViewerTime::periodUtc($days);
        [$previousStart, $previousEnd] = ViewerTime::periodUtc($days, $days);

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
     * Point count and segment length for a sparkline over $start to $end.
     *
     * The viewer-calendar window runs from local midnight to now, so it is a
     * whole number of days plus part of today. Deriving the segment from
     * truncated whole days would leave that tail outside the buckets, and
     * fillBuckets() folds anything past the last bucket into it, which silently
     * doubles the final point. Measuring the window itself keeps every bucket
     * the same width and the last one honest.
     *
     * @return array{0: int, 1: float} the point count and the segment in seconds
     */
    private function getSparklineSegments(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $seconds = (float) ($end->getTimestamp() - $start->getTimestamp());

        if ($seconds <= 0.0) {
            return [0, 0.0];
        }

        $points = min((int) ceil($seconds / 86400), 7);

        return [$points, $seconds / $points];
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
        return $this->getDistinctActiveColumnValues('creator_id', $start, $end);
    }

    /**
     * The shared "did something real" filter behind activation metrics: a
     * non-null creator, a non-system creation source, within the period, on a
     * non-deleted row, applied across every entity table and unioned.
     *
     * $column selects the grain (e.g. `creator_id` for active users,
     * `team_id` for active teams); it is only ever a trusted internal literal,
     * never user input, so it is safe to interpolate into the identifier
     * position.
     *
     * @return Collection<int, int|string>
     */
    private function getDistinctActiveColumnValues(string $column, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_TABLES as $table) {
            $unionParts[] = "SELECT DISTINCT \"{$column}\" FROM \"{$table}\" WHERE \"creator_id\" IS NOT NULL AND \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $sql = "SELECT DISTINCT {$column} FROM (".implode(' UNION ', $unionParts).') AS active_rows';

        return collect(DB::select($sql, $bindings))->pluck($column);
    }
}

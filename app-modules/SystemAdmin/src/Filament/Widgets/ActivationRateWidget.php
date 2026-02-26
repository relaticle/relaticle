<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ActivationRateWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    protected function getStats(): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->getPeriodDates();

        $currentSignups = $this->countSignups($currentStart, $currentEnd);
        $previousSignups = $this->countSignups($previousStart, $previousEnd);

        $currentActivated = $this->countActivatedUsers($currentStart, $currentEnd);
        $previousActivated = $this->countActivatedUsers($previousStart, $previousEnd);

        $currentRate = $currentSignups > 0 ? round($currentActivated / $currentSignups * 100, 1) : 0.0;
        $previousRate = $previousSignups > 0 ? round($previousActivated / $previousSignups * 100, 1) : 0.0;

        return [
            $this->buildSignupsStat($currentSignups, $previousSignups, $currentStart, $currentEnd),
            $this->buildActivatedStat($currentActivated, $previousActivated, $currentStart, $currentEnd),
            $this->buildActivationRateStat($currentRate, $previousRate),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function getPeriodDates(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $currentEnd = CarbonImmutable::now();
        $currentStart = $currentEnd->subDays($days);
        $previousEnd = $currentStart;
        $previousStart = $previousEnd->subDays($days);

        return [$currentStart, $currentEnd, $previousStart, $previousEnd];
    }

    private function countSignups(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return User::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function countActivatedUsers(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_TABLES as $table) {
            $unionParts[] = "SELECT DISTINCT \"creator_id\" FROM \"{$table}\" WHERE \"creator_id\" IS NOT NULL AND \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $unionSql = implode(' UNION ', $unionParts);
        $sql = "SELECT COUNT(DISTINCT creator_id) as total FROM ({$unionSql}) AS active_creators
                WHERE creator_id IN (SELECT id FROM users WHERE created_at BETWEEN ? AND ?)";

        $bindings[] = $start->toDateTimeString();
        $bindings[] = $end->toDateTimeString();

        $result = DB::selectOne($sql, $bindings);

        return (int) ($result->total ?? 0);
    }

    private function buildSignupsStat(
        int $current,
        int $previous,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Stat {
        $change = $this->calculateChange($current, $previous);

        return Stat::make('Sign-ups', number_format($current))
            ->description("this period{$this->formatChange($change)}")
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildSignupsSparkline($start, $end));
    }

    private function buildActivatedStat(
        int $current,
        int $previous,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Stat {
        $change = $this->calculateChange($current, $previous);

        return Stat::make('Activated Users', number_format($current))
            ->description("created a record{$this->formatChange($change)}")
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildActivatedSparkline($start, $end));
    }

    private function buildActivationRateStat(
        float $currentRate,
        float $previousRate,
    ): Stat {
        $rateChange = $previousRate > 0
            ? round($currentRate - $previousRate, 1)
            : ($currentRate > 0 ? $currentRate : 0.0);

        $changeText = $rateChange !== 0.0
            ? ' ('.($rateChange > 0 ? '+' : '')."{$rateChange}pp)"
            : '';

        return Stat::make('Activation Rate', "{$currentRate}%")
            ->description("vs {$previousRate}% previous{$changeText}")
            ->descriptionIcon($rateChange >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($rateChange >= 0 ? 'success' : 'danger');
    }

    /**
     * @return array<int, int>
     */
    private function buildSignupsSparkline(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);

        if ($points <= 0) {
            return [0];
        }

        $segmentSeconds = ($days / $points) * 86400;
        $bucketExpr = $this->bucketExpression();

        $rows = User::query()
            ->selectRaw("{$bucketExpr} AS bucket, COUNT(*) AS cnt", [
                $start->toDateTimeString(),
                $segmentSeconds,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->get();

        return $this->fillBuckets($rows, $points);
    }

    /**
     * @return array<int, int>
     */
    private function buildActivatedSparkline(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);

        if ($points <= 0) {
            return [0];
        }

        $segmentSeconds = ($days / $points) * 86400;
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_TABLES as $table) {
            $unionParts[] = "SELECT DISTINCT \"creator_id\", \"created_at\" FROM \"{$table}\" WHERE \"creator_id\" IS NOT NULL AND \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $unionSql = implode(' UNION ', $unionParts);
        $bucketExpr = $this->bucketExpression();

        $sql = "SELECT {$bucketExpr} AS bucket, COUNT(DISTINCT creator_id) AS cnt FROM ({$unionSql}) AS all_creators GROUP BY 1 ORDER BY 1";

        $rows = DB::select($sql, [$start->toDateTimeString(), $segmentSeconds, ...$bindings]);

        return $this->fillBuckets(collect($rows), $points);
    }

    /**
     * @return array<int, int>
     */
    private function fillBuckets(Collection $rows, int $points): array
    {
        $buckets = array_fill(0, $points, 0);

        foreach ($rows as $row) {
            $idx = (int) $row->bucket;

            if ($idx >= 0 && $idx < $points) {
                $buckets[$idx] = (int) $row->cnt;
            }
        }

        return $buckets;
    }

    private function bucketExpression(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return 'CAST((julianday("created_at") - julianday(?)) * 86400 / ? AS INTEGER)';
        }

        return 'FLOOR(EXTRACT(EPOCH FROM ("created_at" - ?::timestamp)) / ?)';
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
}

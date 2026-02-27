<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasPeriodComparison;

final class ActivationRateWidget extends StatsOverviewWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

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
            $this->buildCountStat('Sign-ups', 'this period', $currentSignups, $previousSignups, $this->buildSignupsSparkline($currentStart, $currentEnd)),
            $this->buildCountStat('Activated Users', 'created a record', $currentActivated, $previousActivated, $this->buildActivatedSparkline($currentStart, $currentEnd)),
            $this->buildActivationRateStat($currentRate, $previousRate),
        ];
    }

    private function countSignups(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return User::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function countActivatedUsers(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $activeCreatorIds = $this->getActiveCreatorIds($start, $end);

        if ($activeCreatorIds->isEmpty()) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $activeCreatorIds)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /** @param array<int, int>|null $chart */
    private function buildCountStat(
        string $label,
        string $description,
        int $current,
        int $previous,
        ?array $chart = null,
    ): Stat {
        $change = $this->calculateChange($current, $previous);

        $stat = Stat::make($label, number_format($current))
            ->description("{$description}{$this->formatChange($change)}")
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');

        if ($chart !== null) {
            $stat->chart($chart);
        }

        return $stat;
    }

    private function buildActivationRateStat(float $currentRate, float $previousRate): Stat
    {
        $rateChange = round($currentRate - $previousRate, 1);

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
}

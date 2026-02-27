<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasPeriodComparison;

final class PlatformGrowthStatsWidget extends StatsOverviewWidget
{
    use HasPeriodComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /** @var array<int, class-string> */
    private const array ENTITY_CLASSES = [Company::class, People::class, Task::class, Note::class, Opportunity::class];

    protected function getStats(): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->getPeriodDates();

        return [
            $this->buildUsersStat($currentStart, $currentEnd, $previousStart, $previousEnd),
            $this->buildTeamsStat($currentStart, $currentEnd, $previousStart, $previousEnd),
            $this->buildRecordsStat($currentStart, $currentEnd, $previousStart, $previousEnd),
            $this->buildActiveUsersStat($currentStart, $currentEnd, $previousStart, $previousEnd),
        ];
    }

    private function buildUsersStat(
        CarbonImmutable $currentStart,
        CarbonImmutable $currentEnd,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
    ): Stat {
        $total = User::query()->count();
        $newCurrent = User::query()->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $newPrevious = User::query()->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $change = $this->calculateChange($newCurrent, $newPrevious);

        return Stat::make('Total Users', number_format($total))
            ->description("{$newCurrent} new this period".$this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildModelSparkline(User::class, $currentStart, $currentEnd));
    }

    private function buildTeamsStat(
        CarbonImmutable $currentStart,
        CarbonImmutable $currentEnd,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
    ): Stat {
        $nonPersonalScope = fn (Builder $query): Builder => $query->where('personal_team', false);

        $total = Team::query()->where('personal_team', false)->count();
        $newCurrent = Team::query()->where('personal_team', false)->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $newPrevious = Team::query()->where('personal_team', false)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $change = $this->calculateChange($newCurrent, $newPrevious);

        return Stat::make('Total Teams', number_format($total))
            ->description("{$newCurrent} new this period".$this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildModelSparkline(Team::class, $currentStart, $currentEnd, $nonPersonalScope));
    }

    private function buildRecordsStat(
        CarbonImmutable $currentStart,
        CarbonImmutable $currentEnd,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
    ): Stat {
        $currentRecords = $this->countRecordsInPeriod($currentStart, $currentEnd);
        $previousRecords = $this->countRecordsInPeriod($previousStart, $previousEnd);
        $change = $this->calculateChange($currentRecords, $previousRecords);

        return Stat::make('New Records', number_format($currentRecords))
            ->description("{$currentRecords} this period".$this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildRecordsSparkline($currentStart, $currentEnd));
    }

    private function buildActiveUsersStat(
        CarbonImmutable $currentStart,
        CarbonImmutable $currentEnd,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
    ): Stat {
        $currentActive = $this->countActiveUsers($currentStart, $currentEnd);
        $previousActive = $this->countActiveUsers($previousStart, $previousEnd);
        $change = $this->calculateChange($currentActive, $previousActive);

        return Stat::make('Active Users', number_format($currentActive))
            ->description('sessions in this period'.$this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');
    }

    private function countRecordsInPeriod(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_CLASSES as $class) {
            $table = (new $class)->getTable();
            $unionParts[] = "SELECT COUNT(*) as cnt FROM \"{$table}\" WHERE \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $sql = implode(' UNION ALL ', $unionParts);
        $result = DB::selectOne("SELECT SUM(cnt) as total FROM ({$sql}) as counts", $bindings);

        return (int) ($result->total ?? 0);
    }

    private function countActiveUsers(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return DB::table('sessions')
            ->whereNotNull('user_id')
            ->whereBetween('last_activity', [$start->timestamp, $end->timestamp])
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * @return array<int, int>
     */
    private function buildModelSparkline(string $modelClass, CarbonImmutable $start, CarbonImmutable $end, ?\Closure $scope = null): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);

        if ($points <= 0) {
            return [0];
        }

        $segmentSeconds = ($days / $points) * 86400;
        $bucketExpr = $this->bucketExpression();

        $query = $modelClass::query()
            ->selectRaw("{$bucketExpr} AS bucket, COUNT(*) AS cnt", [
                $start->toDateTimeString(),
                $segmentSeconds,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('1')
            ->orderByRaw('1');

        if ($scope instanceof \Closure) {
            $scope($query);
        }

        return $this->fillBuckets($query->get(), $points);
    }

    /**
     * @return array<int, int>
     */
    private function buildRecordsSparkline(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);

        if ($points <= 0) {
            return [0];
        }

        $segmentSeconds = ($days / $points) * 86400;
        $unionParts = [];
        $bindings = [];

        foreach (self::ENTITY_CLASSES as $class) {
            $table = (new $class)->getTable();
            $unionParts[] = "SELECT \"created_at\" FROM \"{$table}\" WHERE \"creation_source\" != ? AND \"created_at\" BETWEEN ? AND ? AND \"deleted_at\" IS NULL";
            $bindings[] = CreationSource::SYSTEM->value;
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        $unionSql = implode(' UNION ALL ', $unionParts);
        $bucketExpr = $this->bucketExpression();
        $sql = "SELECT {$bucketExpr} AS bucket, COUNT(*) AS cnt FROM ({$unionSql}) AS all_records GROUP BY 1 ORDER BY 1";

        $rows = DB::select($sql, [$start->toDateTimeString(), $segmentSeconds, ...$bindings]);

        return $this->fillBuckets(collect($rows), $points);
    }
}

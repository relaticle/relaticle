# Sysadmin Dashboard Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace CRM-focused sysadmin dashboard with growth and adoption metrics for measuring Relaticle's platform health.

**Architecture:** Delete 3 existing CRM widgets + 1 trait. Modify the Dashboard page to add a time period filter via `HasFiltersAction`. Create 4 new widgets (stats overview, signup trend chart, record distribution doughnut, top teams table) that all read from the page filter via `InteractsWithPageFilters`. All data computed from existing tables — no new migrations.

**Tech Stack:** Filament v5 (`HasFiltersAction`, `InteractsWithPageFilters`, `StatsOverviewWidget`, `ChartWidget`, `TableWidget`), Laravel 12, Pest v4.

---

### Task 1: Delete old widgets and trait

**Files:**
- Delete: `app-modules/SystemAdmin/src/Filament/Widgets/BusinessOverviewWidget.php`
- Delete: `app-modules/SystemAdmin/src/Filament/Widgets/SalesAnalyticsChartWidget.php`
- Delete: `app-modules/SystemAdmin/src/Filament/Widgets/TeamPerformanceTableWidget.php`
- Delete: `app-modules/SystemAdmin/src/Filament/Widgets/Concerns/HasCustomFieldQueries.php`

**Step 1: Delete the files**

```bash
rm app-modules/SystemAdmin/src/Filament/Widgets/BusinessOverviewWidget.php
rm app-modules/SystemAdmin/src/Filament/Widgets/SalesAnalyticsChartWidget.php
rm app-modules/SystemAdmin/src/Filament/Widgets/TeamPerformanceTableWidget.php
rm app-modules/SystemAdmin/src/Filament/Widgets/Concerns/HasCustomFieldQueries.php
rmdir app-modules/SystemAdmin/src/Filament/Widgets/Concerns/
```

**Step 2: Verify no references remain**

```bash
grep -r "BusinessOverviewWidget\|SalesAnalyticsChartWidget\|TeamPerformanceTableWidget\|HasCustomFieldQueries" app-modules/SystemAdmin/
```

Expected: No matches.

**Step 3: Run existing tests**

```bash
php artisan test --compact --filter=SystemAdmin
```

Expected: All pass (widgets were auto-discovered, not explicitly registered).

**Step 4: Commit**

```bash
git add -A && git commit -m "chore: remove old CRM dashboard widgets"
```

---

### Task 2: Update Dashboard page with time period filter

**Files:**
- Modify: `app-modules/SystemAdmin/src/Filament/Pages/Dashboard.php`

**Step 1: Implement the updated Dashboard**

Replace the entire file content with:

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;

final class Dashboard extends BaseDashboard
{
    use HasFiltersAction;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected ?string $heading = 'Relaticle Admin';

    protected ?string $subheading = 'Platform growth and adoption metrics.';

    protected static ?string $navigationLabel = 'Dashboard';

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->schema([
                    Select::make('period')
                        ->label('Time Period')
                        ->options([
                            '7' => 'Last 7 days',
                            '30' => 'Last 30 days',
                            '90' => 'Last 90 days',
                            '365' => 'Last 12 months',
                        ])
                        ->default('30'),
                ]),
            Action::make('view-site')
                ->label('View Website')
                ->url(config('app.url'))
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->openUrlInNewTab(),
        ];
    }
}
```

**Step 2: Verify the dashboard loads**

```bash
php artisan test --compact --filter=SystemAdmin
```

Expected: All pass.

**Step 3: Commit**

```bash
git add -A && git commit -m "feat: add time period filter to sysadmin dashboard"
```

---

### Task 3: Create PlatformGrowthStatsWidget

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Widgets/PlatformGrowthStatsWidget.php`
- Create: `tests/Feature/SystemAdmin/Widgets/PlatformGrowthStatsWidgetTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\PlatformGrowthStatsWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('sysadmin');
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
});

it('renders the platform growth stats widget', function () {
    livewire(PlatformGrowthStatsWidget::class)
        ->assertSuccessful();
});

it('displays correct user count', function () {
    User::factory()->count(3)->create();

    livewire(PlatformGrowthStatsWidget::class)
        ->assertSeeText('3');
});

it('displays correct non-personal team count', function () {
    $user = User::factory()->withPersonalTeam()->create();
    Team::factory()->count(2)->create(['user_id' => $user->id, 'personal_team' => false]);

    livewire(PlatformGrowthStatsWidget::class)
        ->assertSeeText('2');
});

it('counts new records excluding system-created', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    Company::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);
    Company::factory()->for($team)->create(['creation_source' => CreationSource::SYSTEM]);
    People::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);
    Task::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);
    Note::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);
    Opportunity::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);

    livewire(PlatformGrowthStatsWidget::class)
        ->assertSeeText('5');
});
```

**Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=PlatformGrowthStatsWidget
```

Expected: FAIL — class not found.

**Step 3: Implement the widget**

Create `app-modules/SystemAdmin/src/Filament/Widgets/PlatformGrowthStatsWidget.php`:

```php
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

final class PlatformGrowthStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

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
            ->description("{$newCurrent} new this period" . $this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildSparkline(User::class, $currentStart, $currentEnd));
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
            ->description("{$newCurrent} new this period" . $this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger')
            ->chart($this->buildSparkline(Team::class, $currentStart, $currentEnd, $nonPersonalScope));
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
            ->description("{$currentRecords} this period" . $this->formatChange($change))
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
            ->description('sessions in this period' . $this->formatChange($change))
            ->descriptionIcon($change >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
            ->color($change >= 0 ? 'success' : 'danger');
    }

    private function countRecordsInPeriod(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $entityClasses = [Company::class, People::class, Task::class, Note::class, Opportunity::class];

        return collect($entityClasses)->sum(
            fn (string $class): int => $class::query()
                ->where('creation_source', '!=', CreationSource::SYSTEM)
                ->whereBetween('created_at', [$start, $end])
                ->count()
        );
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
    private function buildSparkline(string $modelClass, CarbonImmutable $start, CarbonImmutable $end, ?\Closure $scope = null): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);

        return collect(range($points - 1, 0))
            ->map(function (int $i) use ($modelClass, $start, $end, $points, $days, $scope): int {
                $segmentStart = $start->addDays((int) (($points - 1 - $i) * ($days / $points)));
                $segmentEnd = $start->addDays((int) (($points - $i) * ($days / $points)));

                $query = $modelClass::query()->whereBetween('created_at', [$segmentStart, $segmentEnd]);

                if ($scope) {
                    $scope($query);
                }

                return $query->count();
            })
            ->toArray();
    }

    /**
     * @return array<int, int>
     */
    private function buildRecordsSparkline(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end);
        $points = min($days, 7);
        $entityClasses = [Company::class, People::class, Task::class, Note::class, Opportunity::class];

        return collect(range($points - 1, 0))
            ->map(function (int $i) use ($start, $days, $points, $entityClasses): int {
                $segmentStart = $start->addDays((int) (($points - 1 - $i) * ($days / $points)));
                $segmentEnd = $start->addDays((int) (($points - $i) * ($days / $points)));

                return collect($entityClasses)->sum(
                    fn (string $class): int => $class::query()
                        ->where('creation_source', '!=', CreationSource::SYSTEM)
                        ->whereBetween('created_at', [$segmentStart, $segmentEnd])
                        ->count()
                );
            })
            ->toArray();
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
```

**Step 4: Run the tests**

```bash
php artisan test --compact --filter=PlatformGrowthStatsWidget
```

Expected: All pass.

**Step 5: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

**Step 6: Commit**

```bash
git add -A && git commit -m "feat: add platform growth stats widget"
```

---

### Task 4: Create SignupTrendChartWidget

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Widgets/SignupTrendChartWidget.php`
- Create: `tests/Feature/SystemAdmin/Widgets/SignupTrendChartWidgetTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\SignupTrendChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('sysadmin');
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
});

it('renders the signup trend chart widget', function () {
    livewire(SignupTrendChartWidget::class)
        ->assertSuccessful();
});

it('returns line chart type', function () {
    $widget = new SignupTrendChartWidget;
    $reflection = new ReflectionMethod($widget, 'getType');

    expect($reflection->invoke($widget))->toBe('line');
});
```

**Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=SignupTrendChartWidget
```

**Step 3: Implement the widget**

Create `app-modules/SystemAdmin/src/Filament/Widgets/SignupTrendChartWidget.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

final class SignupTrendChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    /**
     * @return array<string, mixed>
     */
    public function getColumnSpan(): array
    {
        return [
            'default' => 'full',
            'lg' => 2,
        ];
    }

    public function getHeading(): string
    {
        return 'Signup Trends';
    }

    public function getDescription(): string
    {
        return 'New users and teams over time.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);

        $intervals = $this->buildIntervals($start, $end, $days);
        $labels = $intervals->pluck('label')->toArray();

        $userCounts = $intervals->map(
            fn (array $interval): int => User::query()
                ->whereBetween('created_at', [$interval['start'], $interval['end']])
                ->count()
        )->toArray();

        $teamCounts = $intervals->map(
            fn (array $interval): int => Team::query()
                ->where('personal_team', false)
                ->whereBetween('created_at', [$interval['start'], $interval['end']])
                ->count()
        )->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $userCounts,
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                ],
                [
                    'label' => 'New Teams',
                    'data' => $teamCounts,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildIntervals(CarbonImmutable $start, CarbonImmutable $end, int $days): \Illuminate\Support\Collection
    {
        if ($days <= 30) {
            return $this->buildDailyIntervals($start, $days);
        }

        if ($days <= 90) {
            return $this->buildWeeklyIntervals($start, $end);
        }

        return $this->buildMonthlyIntervals($start, $end);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildDailyIntervals(CarbonImmutable $start, int $days): \Illuminate\Support\Collection
    {
        return collect(range(0, $days - 1))->map(fn (int $i): array => [
            'label' => $start->addDays($i)->format('M j'),
            'start' => $start->addDays($i)->startOfDay(),
            'end' => $start->addDays($i)->endOfDay(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildWeeklyIntervals(CarbonImmutable $start, CarbonImmutable $end): \Illuminate\Support\Collection
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

    /**
     * @return \Illuminate\Support\Collection<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildMonthlyIntervals(CarbonImmutable $start, CarbonImmutable $end): \Illuminate\Support\Collection
    {
        $intervals = collect();
        $current = $start->startOfMonth();

        while ($current->lt($end)) {
            $monthEnd = $current->endOfMonth()->min($end);
            $intervals->push([
                'label' => $current->format('M Y'),
                'start' => $current,
                'end' => $monthEnd,
            ]);
            $current = $current->addMonth();
        }

        return $intervals;
    }
}
```

**Step 4: Run the tests**

```bash
php artisan test --compact --filter=SignupTrendChartWidget
```

**Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add signup trend chart widget"
```

---

### Task 5: Create RecordDistributionChartWidget

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Widgets/RecordDistributionChartWidget.php`
- Create: `tests/Feature/SystemAdmin/Widgets/RecordDistributionChartWidgetTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\RecordDistributionChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('sysadmin');
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
});

it('renders the record distribution chart widget', function () {
    livewire(RecordDistributionChartWidget::class)
        ->assertSuccessful();
});

it('returns doughnut chart type', function () {
    $widget = new RecordDistributionChartWidget;
    $reflection = new ReflectionMethod($widget, 'getType');

    expect($reflection->invoke($widget))->toBe('doughnut');
});
```

**Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=RecordDistributionChartWidget
```

**Step 3: Implement the widget**

Create `app-modules/SystemAdmin/src/Filament/Widgets/RecordDistributionChartWidget.php`:

```php
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

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

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
                        '#6366f1', // indigo - Companies
                        '#8b5cf6', // violet - People
                        '#10b981', // emerald - Tasks
                        '#f59e0b', // amber - Notes
                        '#3b82f6', // blue - Opportunities
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
```

**Step 4: Run the tests**

```bash
php artisan test --compact --filter=RecordDistributionChartWidget
```

**Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add record distribution doughnut chart widget"
```

---

### Task 6: Create TopTeamsTableWidget

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Widgets/TopTeamsTableWidget.php`
- Create: `tests/Feature/SystemAdmin/Widgets/TopTeamsTableWidgetTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\TopTeamsTableWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('sysadmin');
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
});

it('renders the top teams table widget', function () {
    livewire(TopTeamsTableWidget::class)
        ->assertSuccessful();
});

it('shows non-personal teams with records', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $team = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);

    Company::factory()->for($team)->create(['creation_source' => CreationSource::WEB]);

    livewire(TopTeamsTableWidget::class)
        ->assertCanSeeTableRecords([$team]);
});

it('excludes personal teams', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $personalTeam = $user->currentTeam;

    Company::factory()->for($personalTeam)->create(['creation_source' => CreationSource::WEB]);

    livewire(TopTeamsTableWidget::class)
        ->assertCanNotSeeTableRecords([$personalTeam]);
});
```

**Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=TopTeamsTableWidget
```

**Step 3: Implement the widget**

Create `app-modules/SystemAdmin/src/Filament/Widgets/TopTeamsTableWidget.php`:

```php
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TopTeamsTableWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top Teams';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);
        $systemSource = CreationSource::SYSTEM->value;

        return $table
            ->query(
                Team::query()
                    ->where('personal_team', false)
                    ->addSelect([
                        'teams.*',
                        DB::raw("(
                            (SELECT COUNT(*) FROM companies WHERE companies.team_id = teams.id AND companies.deleted_at IS NULL AND companies.creation_source != '{$systemSource}' AND companies.created_at BETWEEN '{$start}' AND '{$end}')
                            + (SELECT COUNT(*) FROM people WHERE people.team_id = teams.id AND people.deleted_at IS NULL AND people.creation_source != '{$systemSource}' AND people.created_at BETWEEN '{$start}' AND '{$end}')
                            + (SELECT COUNT(*) FROM tasks WHERE tasks.team_id = teams.id AND tasks.deleted_at IS NULL AND tasks.creation_source != '{$systemSource}' AND tasks.created_at BETWEEN '{$start}' AND '{$end}')
                            + (SELECT COUNT(*) FROM notes WHERE notes.team_id = teams.id AND notes.deleted_at IS NULL AND notes.creation_source != '{$systemSource}' AND notes.created_at BETWEEN '{$start}' AND '{$end}')
                            + (SELECT COUNT(*) FROM opportunities WHERE opportunities.team_id = teams.id AND opportunities.deleted_at IS NULL AND opportunities.creation_source != '{$systemSource}' AND opportunities.created_at BETWEEN '{$start}' AND '{$end}')
                        ) as records_count"),
                        DB::raw('(SELECT COUNT(*) FROM team_user WHERE team_user.team_id = teams.id) as members_count'),
                        DB::raw('(SELECT COUNT(*) FROM custom_fields WHERE custom_fields.tenant_id = teams.id) as custom_fields_count'),
                        DB::raw("GREATEST(
                            COALESCE((SELECT MAX(created_at) FROM companies WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM people WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM tasks WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
                            COALESCE((SELECT MAX(created_at) FROM notes WHERE team_id = teams.id AND creation_source != '{$systemSource}'), '1970-01-01'),
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
```

**Step 4: Run the tests**

```bash
php artisan test --compact --filter=TopTeamsTableWidget
```

**Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add top teams table widget"
```

---

### Task 7: Configure dashboard widget grid and final verification

**Files:**
- Modify: `app-modules/SystemAdmin/src/Filament/Pages/Dashboard.php` (add widget columns)

**Step 1: Add widget grid columns to Dashboard**

Add this method to `Dashboard.php`:

```php
public function getColumns(): int|string|array
{
    return [
        'default' => 1,
        'lg' => 3,
    ];
}
```

**Step 2: Run all SystemAdmin tests**

```bash
php artisan test --compact --filter=SystemAdmin
```

Expected: All pass.

**Step 3: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Expected: No new errors.

**Step 4: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: configure dashboard widget grid layout"
```

---

### Task 8: Manual verification

**Step 1: Open the sysadmin dashboard in browser**

Navigate to `sysadmin.relaticle.test` (or the local sysadmin URL) and verify:

- [ ] Dashboard loads without errors
- [ ] Filter button appears in header, opens modal with period select
- [ ] 4 stat cards display in row 1 (Total Users, Total Teams, New Records, Active Users)
- [ ] Signup trend line chart in row 2 left
- [ ] Record distribution doughnut chart in row 2 right
- [ ] Top teams table in row 3 with correct columns
- [ ] Changing filter period updates all widgets
- [ ] No JavaScript errors in browser console

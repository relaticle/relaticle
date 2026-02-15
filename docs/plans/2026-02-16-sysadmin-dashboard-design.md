# Sysadmin Dashboard Redesign

## Context

The current sysadmin dashboard shows CRM business metrics (pipeline value, opportunities, task completion, sales chart, team performance). These are useful for CRM users but don't help measure platform growth or adoption.

Relaticle currently has only free users with no billing system. The primary need is to understand: are people signing up? Are they actually using the platform? Which teams are most active?

## Approach

Replace all existing CRM widgets with growth and adoption focused widgets. Use Filament's `HasFiltersAction` for a global time period filter. All data is computed from existing tables using `created_at` timestamps — no new migrations or scheduled commands.

## Dashboard Page

The Dashboard page uses `HasFiltersAction` with a filter button in the header that opens a modal.

Filter options:
- Last 7 days
- Last 30 days (default)
- Last 90 days
- Last 12 months

All widgets use `InteractsWithPageFilters` to receive the selected period and compute:
- **Current period** — the selected date range
- **Previous period** — the equivalent range before the current one (for comparison %)

## Widget 1: Platform Growth Stats (Row 1)

`StatsOverviewWidget`, sort=1, full width. 4 stat cards with sparkline charts.

| Stat | Query | Notes |
|------|-------|-------|
| Total Users | `users` count | Cumulative total, description shows "X new this period", % change compares new signups between periods |
| Total Teams | `teams` where `personal_team = false` | Same cumulative + new pattern |
| Total Records | companies + people + tasks + notes + opportunities created in period | New records only (not cumulative), excludes `creation_source = SYSTEM` |
| Active Users | Distinct `user_id` from `sessions` where `last_activity` in period | Rough proxy for engagement |

Sparklines show daily new counts within the selected period.

## Widget 2: Signup Trend Chart (Row 2, Left)

`ChartWidget` (line), sort=2, spans 2/3 width on large screens.

Two datasets:
- New Users per interval
- New Teams (non-personal) per interval

Granularity adapts to period:
- 7d / 30d: daily data points
- 90d: weekly data points
- 12m: monthly data points

## Widget 3: Record Distribution Chart (Row 2, Right)

`ChartWidget` (doughnut), sort=3, spans 1/3 width on large screens.

Shows records created in the period by entity type:
- Companies
- People
- Tasks
- Notes
- Opportunities

Excludes `creation_source = SYSTEM`.

## Widget 4: Top Teams Table (Row 3)

`TableWidget`, sort=4, full width.

| Column | Source |
|--------|--------|
| Team | `teams.name` |
| Owner | `users.name` via `teams.user_id` |
| Members | Count of `team_user` rows |
| Records | Sum of entity records created in period |
| Custom Fields | Count of `custom_fields` for this team |
| Last Activity | Most recent `created_at` across entity tables |
| Created | `teams.created_at` |

Only non-personal teams with at least 1 record. Excludes `creation_source = SYSTEM`. Sorted by Records descending. Paginated at 10 per page.

## Files

### Delete
- `app-modules/SystemAdmin/src/Filament/Widgets/BusinessOverviewWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/SalesAnalyticsChartWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/TeamPerformanceTableWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/Concerns/HasCustomFieldQueries.php`

### Modify
- `app-modules/SystemAdmin/src/Filament/Pages/Dashboard.php` — add `HasFiltersAction`, `FilterAction` with period Select

### Create
- `app-modules/SystemAdmin/src/Filament/Widgets/PlatformGrowthStatsWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/SignupTrendChartWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/RecordDistributionChartWidget.php`
- `app-modules/SystemAdmin/src/Filament/Widgets/TopTeamsTableWidget.php`

### Testing
Feature tests for each widget verifying correct stat computation with factory data across different time periods.

## Constraints

- No new database tables or migrations
- No scheduled commands
- All queries against existing tables
- Filament v5 APIs only

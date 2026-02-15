<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Filament\Widgets\Concerns\HasCustomFieldQueries;

final class BusinessOverviewWidget extends BaseWidget
{
    use HasCustomFieldQueries;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $businessData = $this->getBusinessData();

        return [
            Stat::make('Pipeline Value', $this->formatCurrency($businessData['pipeline_value']))
                ->description($this->getPipelineDescription($businessData['pipeline_value']))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success')
                ->chart($businessData['pipeline_trend'])
                ->extraAttributes([
                    'class' => 'relative overflow-hidden',
                ]),

            Stat::make('Active Opportunities', number_format($businessData['total_opportunities']))
                ->description($this->getGrowthDescription($businessData['opportunities_growth'], 'opportunities'))
                ->descriptionIcon($businessData['opportunities_growth'] >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color($this->getGrowthColor($businessData['opportunities_growth']))
                ->extraAttributes([
                    'class' => 'relative overflow-hidden',
                ]),

            Stat::make('Task Completion', $businessData['completion_rate'].'%')
                ->description($this->getCompletionDescription($businessData['completion_rate']))
                ->descriptionIcon($this->getCompletionIcon($businessData['completion_rate']))
                ->color($this->getCompletionColor($businessData['completion_rate']))
                ->extraAttributes([
                    'class' => 'relative overflow-hidden',
                ]),

            Stat::make('Total Companies', number_format($businessData['total_companies']))
                ->description($this->getGrowthDescription($businessData['companies_growth'], 'companies'))
                ->descriptionIcon($businessData['companies_growth'] >= 0 ? 'heroicon-o-building-office-2' : 'heroicon-o-building-office')
                ->color($this->getGrowthColor($businessData['companies_growth']))
                ->extraAttributes([
                    'class' => 'relative overflow-hidden',
                ]),
        ];
    }

    /**
     * @return array{pipeline_value: float, total_opportunities: int, completion_rate: float, total_companies: int, opportunities_growth: float, companies_growth: float, pipeline_trend: array<int, float>}
     */
    private function getBusinessData(): array
    {
        $opportunities = $this->getOpportunitiesWithAmounts();
        $pipelineValue = $opportunities->sum('amount') ?? 0;
        $totalOpportunities = $opportunities->count();

        $totalTasks = Task::query()->where('creation_source', '!=', CreationSource::SYSTEM)->count();
        $completedTasks = $this->countCompletedEntities('tasks', 'task', 'status');
        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        $totalCompanies = Company::query()->where('creation_source', '!=', CreationSource::SYSTEM)->count();

        [$opportunitiesGrowth, $companiesGrowth] = $this->calculateMonthlyGrowth();
        $pipelineTrend = $this->generatePipelineTrend($opportunities);

        return [
            'pipeline_value' => $pipelineValue,
            'total_opportunities' => $totalOpportunities,
            'completion_rate' => $completionRate,
            'total_companies' => $totalCompanies,
            'opportunities_growth' => $opportunitiesGrowth,
            'companies_growth' => $companiesGrowth,
            'pipeline_trend' => $pipelineTrend,
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function getOpportunitiesWithAmounts(): Collection
    {
        return DB::table('opportunities')
            ->leftJoin('custom_field_values as cfv_amount', fn (mixed $join) => $join->on('opportunities.id', '=', 'cfv_amount.entity_id')
                ->where('cfv_amount.entity_type', 'opportunity')
            )
            ->leftJoin('custom_fields as cf_amount', fn (mixed $join) => $join->on('cfv_amount.custom_field_id', '=', 'cf_amount.id')
                ->where('cf_amount.code', 'amount')
            )
            ->whereNull('opportunities.deleted_at')
            ->where('opportunities.creation_source', '!=', CreationSource::SYSTEM->value)
            ->select('cfv_amount.float_value as amount', 'opportunities.created_at')
            ->get();
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function calculateMonthlyGrowth(): array
    {
        $currentMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $opportunitiesThisMonth = Opportunity::query()->where('created_at', '>=', $currentMonth)
            ->where('creation_source', '!=', CreationSource::SYSTEM)
            ->count();
        $opportunitiesLastMonth = Opportunity::query()->whereBetween('created_at', [$lastMonth, $currentMonth])
            ->where('creation_source', '!=', CreationSource::SYSTEM)
            ->count();

        $companiesThisMonth = Company::query()->where('created_at', '>=', $currentMonth)
            ->where('creation_source', '!=', CreationSource::SYSTEM)
            ->count();
        $companiesLastMonth = Company::query()->whereBetween('created_at', [$lastMonth, $currentMonth])
            ->where('creation_source', '!=', CreationSource::SYSTEM)
            ->count();

        $opportunitiesGrowth = $this->calculateGrowthRate($opportunitiesThisMonth, $opportunitiesLastMonth);
        $companiesGrowth = $this->calculateGrowthRate($companiesThisMonth, $companiesLastMonth);

        return [$opportunitiesGrowth, $companiesGrowth];
    }

    private function calculateGrowthRate(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100);
    }

    /**
     * @param  Collection<int, \stdClass>  $opportunities
     * @return array<int, float>
     */
    private function generatePipelineTrend(\Illuminate\Support\Collection $opportunities): array
    {
        return collect(range(6, 0))
            ->map(fn (int $daysAgo): array => [
                'date' => now()->subDays($daysAgo),
                'value' => $opportunities
                    ->whereBetween('created_at', [
                        now()->subDays($daysAgo)->startOfDay(),
                        now()->subDays($daysAgo)->endOfDay(),
                    ])
                    ->sum('amount') ?? 0,
            ])
            ->pluck('value')
            ->toArray();
    }

    private function formatCurrency(float $amount): string
    {
        return match (true) {
            $amount >= 1000000 => '$'.number_format($amount / 1000000, 1).'M',
            $amount >= 1000 => '$'.number_format($amount / 1000, 1).'K',
            default => '$'.number_format($amount, 0)
        };
    }

    private function getPipelineDescription(float $amount): string
    {
        return match (true) {
            $amount >= 1000000 => 'Revenue potential across all opportunities',
            $amount >= 100000 => 'Strong pipeline building momentum',
            $amount > 0 => 'Early stage opportunities in pipeline',
            default => 'No revenue opportunities tracked yet'
        };
    }

    private function getGrowthDescription(float $growth, string $type): string
    {
        return match (true) {
            $growth > 50 => "Exceptional {$type} growth this month",
            $growth > 20 => "Strong {$type} growth this month",
            $growth > 0 => "Positive {$type} growth this month",
            default => "Declining {$type} this month"
        };
    }

    private function getCompletionDescription(float $rate): string
    {
        return match (true) {
            $rate >= 90 => 'Exceptional team productivity',
            $rate >= 70 => 'Strong team performance',
            $rate >= 50 => 'Average team productivity',
            $rate > 0 => 'Below average performance',
            default => 'No completed tasks tracked'
        };
    }

    private function getGrowthColor(float $growth): string
    {
        return match (true) {
            $growth > 20 => 'success',
            $growth > 0 => 'info',
            $growth === 0.0 => 'warning',
            default => 'danger'
        };
    }

    private function getCompletionColor(float $rate): string
    {
        return match (true) {
            $rate >= 80 => 'success',
            $rate >= 60 => 'info',
            $rate >= 40 => 'warning',
            default => 'danger'
        };
    }

    private function getCompletionIcon(float $rate): string
    {
        return match (true) {
            $rate >= 80 => 'heroicon-o-check-badge',
            $rate >= 60 => 'heroicon-o-check-circle',
            $rate >= 40 => 'heroicon-o-clock',
            default => 'heroicon-o-exclamation-triangle'
        };
    }
}

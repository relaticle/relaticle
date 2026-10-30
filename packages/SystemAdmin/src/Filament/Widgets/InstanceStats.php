<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InstanceStats extends StatsOverviewWidget
{
    protected static ?bool $isLazyLoaded = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make(__('instance.stats.users'), number_format(User::query()->count())),
            Stat::make(__('instance.stats.workspaces'), number_format(Workspace::query()->count())),
            Stat::make(__('instance.stats.companies'), number_format(Company::query()->count())),
            Stat::make(__('instance.stats.people'), number_format(People::query()->count())),
            Stat::make(__('instance.stats.opportunities'), number_format(Opportunity::query()->count())),
        ];
    }
}

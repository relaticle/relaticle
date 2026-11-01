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
use Illuminate\Support\Facades\Cache;

final class InstanceStats extends StatsOverviewWidget
{
    protected static ?bool $isLazyLoaded = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = Cache::remember('sysadmin.instance-stats', now()->addMinute(), fn (): array => [
            'users' => User::query()->count(),
            'workspaces' => Workspace::query()->count(),
            'companies' => Company::query()->count(),
            'people' => People::query()->count(),
            'opportunities' => Opportunity::query()->count(),
        ]);

        return array_map(
            fn (string $key): Stat => Stat::make(__("instance.stats.{$key}"), number_format($counts[$key])),
            array_keys($counts),
        );
    }
}

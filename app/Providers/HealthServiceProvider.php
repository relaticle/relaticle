<?php

declare(strict_types=1);

namespace App\Providers;

use App\Health\AnthropicModelCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DatabaseSizeCheck;
use Spatie\Health\Checks\Checks\DatabaseTableSizeCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

final class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Health::checks([
            DatabaseCheck::new(),

            DatabaseConnectionCountCheck::new()
                ->warnWhenMoreConnectionsThan(50)
                ->failWhenMoreConnectionsThan(100),

            DatabaseSizeCheck::new()
                ->failWhenSizeAboveGb(errorThresholdGb: 10.0),

            DatabaseTableSizeCheck::new()
                ->table('custom_field_values', maxSizeInMb: 5_000)
                ->table('notes', maxSizeInMb: 5_000)
                ->table('companies', maxSizeInMb: 2_000)
                ->table('people', maxSizeInMb: 2_000)
                ->table('opportunities', maxSizeInMb: 2_000)
                ->table('tasks', maxSizeInMb: 2_000)
                ->table('media', maxSizeInMb: 5_000)
                ->table('jobs', maxSizeInMb: 1_000),

            RedisCheck::new(),

            RedisMemoryUsageCheck::new()
                ->warnWhenAboveMb(500)
                ->failWhenAboveMb(1_000),

            HorizonCheck::new(),

            QueueCheck::new()
                ->name('Queue: default'),

            QueueCheck::new()
                ->name('Queue: imports')
                ->onQueue('imports'),

            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(90),

            CpuLoadCheck::new()
                ->failWhenLoadIsHigherInTheLast5Minutes(4.0)
                ->failWhenLoadIsHigherInTheLast15Minutes(2.0),

            DebugModeCheck::new(),

            EnvironmentCheck::new(),

            ScheduleCheck::new()
                ->heartbeatMaxAgeInMinutes(2),

            SecurityAdvisoriesCheck::new(),

            CacheCheck::new(),

            AnthropicModelCheck::new()
                ->name('Anthropic: Summary Model'),
        ]);
    }

    private function isEnabled(): bool
    {
        return (bool) config('app.health_checks_enabled', false);
    }
}

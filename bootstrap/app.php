<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Sentry\Laravel\Integration;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:generate-sitemap')->daily();
        $schedule->command('import:cleanup')->hourly();
        $schedule->command('queue:prune-batches --hours=24')->daily();

        $schedule->call(function (): void {
            ConnectedAccount::where('status', EmailAccountStatus::ACTIVE)
                ->whereNotNull('sync_cursor')
                ->each(fn (ConnectedAccount $account) => IncrementalEmailSyncJob::dispatch($account));
        })
            ->everyFiveMinutes()
            ->name('email:incremental-sync')
            ->withoutOverlapping();

        if (config('app.health_checks_enabled')) {
            $schedule->command(RunHealthChecksCommand::class)->everyMinute();
            $schedule->command(DispatchQueueCheckJobsCommand::class)->everyMinute();
            $schedule->command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
        }
    })
    ->booting(function (): void {
        //        Model::automaticallyEagerLoadRelationships(); TODO: Before enabling this, check the test suite for any issues with eager loading.
    })
    ->create();

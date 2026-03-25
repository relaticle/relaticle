<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Observers\EmailObserver;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-integration.php', 'email-integration');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-integration');

        Email::observe(EmailObserver::class);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                ConnectedAccount::where('status', EmailAccountStatus::ACTIVE)
                    ->cursor()
                    ->each(fn (ConnectedAccount $account) => IncrementalEmailSyncJob::dispatch($account));
            })->everyFiveMinutes()->name('email-incremental-sync');
        });

        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/email-integration.php' => config_path('email-integration.php'),
            ], 'email-integration-config');
        }
    }
}

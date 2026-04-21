<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Observers\EmailObserver;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Factories\GoogleCalendarServiceFactory;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email-integration.php', 'email-integration');

        $this->app->bind(CalendarServiceFactoryInterface::class, GoogleCalendarServiceFactory::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-integration');

        Email::observe(EmailObserver::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                ConnectedAccount::query()->where('status', EmailAccountStatus::ACTIVE)
                    ->cursor()
                    ->each(fn (ConnectedAccount $account): PendingDispatch => dispatch(new IncrementalEmailSyncJob($account)));
            })->everyFiveMinutes()->name('email-incremental-sync');

            $schedule->call(function (): void {
                ConnectedAccount::query()
                    ->where('status', EmailAccountStatus::ACTIVE)
                    ->whereJsonContains('capabilities->calendar', true)
                    ->cursor()
                    ->each(fn (ConnectedAccount $account): PendingDispatch => dispatch(new IncrementalCalendarSyncJob($account)));
            })->everyFiveMinutes()->name('calendar-incremental-sync');
        });

        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchOutboxCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/email-integration.php' => config_path('email-integration.php'),
            ], 'email-integration-config');
        }
    }
}

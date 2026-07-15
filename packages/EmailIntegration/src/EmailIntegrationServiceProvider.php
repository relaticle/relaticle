<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use App\Features\EmailIntegration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Console\Commands\BackfillEmailThreadsCommand;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Factories\CalendarServiceFactory;
use Relaticle\EmailIntegration\Services\Factories\MailServiceFactory;
use Relaticle\EmailIntegration\Support\PublicSuffixList;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CalendarServiceFactoryInterface::class, CalendarServiceFactory::class);
        $this->app->bind(MailServiceFactoryInterface::class, MailServiceFactory::class);

        // Parse the Public Suffix List once per process.
        $this->app->singleton(PublicSuffixList::class);
    }

    public function boot(): void
    {
        if (! Feature::active(EmailIntegration::class)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-integration');

        // Email is already observed via #[ObservedBy(EmailObserver::class)] on the model.
        // Registering it again here fires every listener twice (double metric increments,
        // double auto-create) for any create path where participants exist at create time.
        //
        // Incremental email + calendar sync are scheduled in bootstrap/app.php (all
        // scheduled work lives there); do not re-register them here.

        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillEmailThreadsCommand::class,
                DispatchOutboxCommand::class,
            ]);
        }
    }
}

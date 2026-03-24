<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/email-integration.php.php' => config_path('email-integration.php'),
            ], 'email-integration-config');
        }
    }
}

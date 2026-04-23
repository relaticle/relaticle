<?php

declare(strict_types=1);

namespace Relaticle\Documentation;

use App\Features\Documentation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Services\DocumentationService;

final class DocumentationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentation.php', 'documentation');

        $this->app->singleton(DocumentationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! Feature::active(Documentation::class)) {
            return;
        }

        $this->registerRoutes();
        $this->registerViews();
        $this->registerComponents();
        $this->registerPublishing();
    }

    /**
     * Register the package routes.
     */
    private function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
    }

    /**
     * Register the package views.
     */
    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'documentation');
    }

    /**
     * Register Blade components.
     */
    private function registerComponents(): void
    {
        // Register components with the 'documentation::' namespace
        Blade::componentNamespace('Relaticle\\Documentation\\Components', 'documentation');

        // Register anonymous components
        $this->loadViewComponentsAs('documentation', []);
    }

    /**
     * Register publishable resources.
     */
    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // Config
            $this->publishes([
                __DIR__.'/../config/documentation.php' => config_path('documentation.php'),
            ], 'documentation-config');

            // Views
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/documentation'),
            ], 'documentation-views');

            // Markdown
            $this->publishes([
                __DIR__.'/../resources/markdown' => resource_path('markdown/documentation'),
            ], 'documentation-markdown');
        }
    }
}

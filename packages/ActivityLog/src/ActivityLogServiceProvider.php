<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog;

use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ActivityLogServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('activity-log')
            ->hasConfigFile('activity-log')
            ->hasViews('activity-log')
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Renderers\RendererRegistry::class, fn ($app) => new Renderers\RendererRegistry($app));
        $this->app->singleton(Timeline\TimelineCache::class);
    }

    public function packageBooted(): void
    {
        Livewire::component('activity-log-list', Filament\Livewire\TimelineListLivewire::class);
    }
}

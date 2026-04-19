<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog;

use Illuminate\Contracts\Foundation\Application;
use Livewire\Livewire;
use Relaticle\ActivityLog\Renderers\RendererRegistry;
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
        $this->app->singleton(RendererRegistry::class, fn (Application $app): RendererRegistry => new RendererRegistry($app));
        $this->app->singleton(Timeline\TimelineCache::class);
    }

    public function packageBooted(): void
    {
        Livewire::component('timeline-livewire', Filament\Livewire\TimelineLivewire::class);
        Livewire::component('activity-log-list', Filament\Livewire\ActivityLogLivewire::class);
    }
}

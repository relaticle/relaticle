<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Relaticle\ImportWizardNew\Livewire\ImportWizard;
use Relaticle\ImportWizardNew\Livewire\Steps\MappingStep;
use Relaticle\ImportWizardNew\Livewire\Steps\PreviewStep;
use Relaticle\ImportWizardNew\Livewire\Steps\ReviewStep;
use Relaticle\ImportWizardNew\Livewire\Steps\UploadStep;

final class ImportWizardNewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/import-wizard.php', 'import-wizard-new');
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerViews();
        $this->registerLivewireComponents();
    }

    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth'])
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'import-wizard-new');
    }

    private function registerLivewireComponents(): void
    {
        Livewire::component('import-wizard-new.wizard', ImportWizard::class);
        Livewire::component('import-wizard-new.steps.upload', UploadStep::class);
        Livewire::component('import-wizard-new.steps.mapping', MappingStep::class);
        Livewire::component('import-wizard-new.steps.review', ReviewStep::class);
        Livewire::component('import-wizard-new.steps.preview', PreviewStep::class);
    }
}

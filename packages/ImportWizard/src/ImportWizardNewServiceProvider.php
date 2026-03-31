<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Relaticle\ImportWizard\Commands\CleanupImportsCommand;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Livewire\Steps\MappingStep;
use Relaticle\ImportWizard\Livewire\Steps\PreviewStep;
use Relaticle\ImportWizard\Livewire\Steps\ReviewStep;
use Relaticle\ImportWizard\Livewire\Steps\UploadStep;

final class ImportWizardNewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/import-wizard.php', 'import-wizard-new');
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerTranslations();
        $this->registerLivewireComponents();
    }

    private function registerCommands(): void
    {
        $this->commands([
            CleanupImportsCommand::class,
        ]);
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

    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'import-wizard-new');
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

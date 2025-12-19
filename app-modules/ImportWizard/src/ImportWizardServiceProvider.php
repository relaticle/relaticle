<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Relaticle\ImportWizard\Livewire\ImportWizard;

final class ImportWizardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'import-wizard');

        // Register Livewire components
        Livewire::component('import-wizard', ImportWizard::class);
    }
}

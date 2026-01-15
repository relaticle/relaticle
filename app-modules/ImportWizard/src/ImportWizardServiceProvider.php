<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard;

use Filament\Actions\Imports\Models\FailedImportRow as BaseFailedImportRow;
use Filament\Actions\Imports\Models\Import as BaseImport;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Relaticle\ImportWizard\Console\CleanupOrphanedImportsCommand;
use Relaticle\ImportWizard\Console\Commands\UpdateEmailDomainsCommand;
use Relaticle\ImportWizard\Livewire\ImportPreviewTable;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Models\FailedImportRow;
use Relaticle\ImportWizard\Models\Import;

final class ImportWizardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/import-wizard.php', 'import-wizard');

        $this->app->bind(BaseImport::class, Import::class);
        $this->app->bind(BaseFailedImportRow::class, FailedImportRow::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'import-wizard');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Register Livewire components
        Livewire::component('import-wizard', ImportWizard::class);
        Livewire::component('import-preview-table', ImportPreviewTable::class);

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupOrphanedImportsCommand::class,
                UpdateEmailDomainsCommand::class,
            ]);
        }
    }
}

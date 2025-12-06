<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Actions\EnhancedImportAction;
use App\Filament\Imports\BaseImporter;
use App\Filament\Imports\CompanyImporter;
use App\Filament\Imports\NoteImporter;
use App\Filament\Imports\OpportunityImporter;
use App\Filament\Imports\PeopleImporter;
use App\Filament\Imports\TaskImporter;

/**
 * Shared entity configuration for import functionality.
 *
 * Used by both ImportCenter (quick imports) and MigrationWizard (multi-entity migrations).
 */
trait HasImportEntities
{
    /**
     * Get entity configuration for imports.
     *
     * @return array<string, array{label: string, icon: string, description: string, importer: class-string<BaseImporter>, dependencies: array<string>}>
     */
    public function getEntities(): array
    {
        return [
            'companies' => [
                'label' => 'Companies',
                'icon' => 'heroicon-o-building-office-2',
                'description' => 'Import company records with addresses, phone numbers, and custom fields',
                'importer' => CompanyImporter::class,
                'dependencies' => [],
            ],
            'people' => [
                'label' => 'People',
                'icon' => 'heroicon-o-users',
                'description' => 'Import contacts with their company associations and custom fields',
                'importer' => PeopleImporter::class,
                'dependencies' => ['companies'],
            ],
            'opportunities' => [
                'label' => 'Opportunities',
                'icon' => 'heroicon-o-currency-dollar',
                'description' => 'Import deals and opportunities with values, stages, and dates',
                'importer' => OpportunityImporter::class,
                'dependencies' => ['companies'],
            ],
            'tasks' => [
                'label' => 'Tasks',
                'icon' => 'heroicon-o-clipboard-document-check',
                'description' => 'Import tasks with priorities, statuses, and entity associations',
                'importer' => TaskImporter::class,
                'dependencies' => [],
            ],
            'notes' => [
                'label' => 'Notes',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Import notes linked to companies, people, or opportunities',
                'importer' => NoteImporter::class,
                'dependencies' => [],
            ],
        ];
    }

    /**
     * Create an import action for a specific entity type.
     */
    protected function makeImportAction(string $entityType): EnhancedImportAction
    {
        $config = $this->getEntities()[$entityType];

        return EnhancedImportAction::make("import_{$entityType}")
            ->importer($config['importer'])
            ->label("Import {$config['label']}")
            ->modalHeading("Import {$config['label']}")
            ->color('primary');
    }

    public function importCompaniesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('companies');
    }

    public function importPeopleAction(): EnhancedImportAction
    {
        return $this->makeImportAction('people');
    }

    public function importOpportunitiesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('opportunities');
    }

    public function importTasksAction(): EnhancedImportAction
    {
        return $this->makeImportAction('tasks');
    }

    public function importNotesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('notes');
    }
}

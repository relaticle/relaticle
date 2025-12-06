<?php

declare(strict_types=1);

namespace App\Livewire\Import;

use App\Filament\Actions\EnhancedImportAction;
use App\Filament\Imports\CompanyImporter;
use App\Filament\Imports\NoteImporter;
use App\Filament\Imports\OpportunityImporter;
use App\Filament\Imports\PeopleImporter;
use App\Filament\Imports\TaskImporter;
use App\Models\MigrationBatch;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Multi-entity migration wizard for guided bulk imports.
 *
 * This wizard helps users import data from multiple entities in the correct
 * dependency order, tracking overall progress through a migration batch.
 */
final class MigrationWizard extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public int $currentStep = 1;

    /** @var array<string, bool> */
    public array $selectedEntities = [
        'companies' => false,
        'people' => false,
        'opportunities' => false,
        'tasks' => false,
        'notes' => false,
    ];

    public ?string $batchId = null;

    public ?string $currentEntity = null;

    /** @var array<string, array{imported: int, failed: int, skipped?: bool, processing?: bool, job_failed?: bool}> */
    public array $importResults = [];

    /**
     * Entity configuration with dependencies and importers.
     *
     * @return array<string, array{label: string, icon: string, description: string, dependencies: array<string>, importer: class-string<\Filament\Actions\Imports\Importer>}>
     */
    public function getEntities(): array
    {
        return [
            'companies' => [
                'label' => 'Companies',
                'icon' => 'heroicon-o-building-office-2',
                'description' => 'Import company records first - they have no dependencies',
                'dependencies' => [],
                'importer' => CompanyImporter::class,
            ],
            'people' => [
                'label' => 'People',
                'icon' => 'heroicon-o-users',
                'description' => 'Import contacts - can be linked to companies',
                'dependencies' => ['companies'],
                'importer' => PeopleImporter::class,
            ],
            'opportunities' => [
                'label' => 'Opportunities',
                'icon' => 'heroicon-o-currency-dollar',
                'description' => 'Import deals - can reference companies and people',
                'dependencies' => ['companies'],
                'importer' => OpportunityImporter::class,
            ],
            'tasks' => [
                'label' => 'Tasks',
                'icon' => 'heroicon-o-clipboard-document-check',
                'description' => 'Import tasks - can optionally link to companies, people, or opportunities',
                'dependencies' => [],
                'importer' => TaskImporter::class,
            ],
            'notes' => [
                'label' => 'Notes',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Import notes - can optionally link to companies, people, or opportunities',
                'dependencies' => [],
                'importer' => NoteImporter::class,
            ],
        ];
    }

    /**
     * Get the ordered list of entities to import based on dependencies.
     *
     * @return array<string>
     */
    public function getImportOrder(): array
    {
        $order = ['companies', 'people', 'opportunities', 'tasks', 'notes'];

        return array_values(array_filter($order, fn (string $entity): bool => $this->selectedEntities[$entity] ?? false));
    }

    /**
     * Check if an entity can be selected based on its dependencies.
     */
    public function canSelectEntity(string $entity): bool
    {
        $entities = $this->getEntities();
        $dependencies = $entities[$entity]['dependencies'] ?? [];

        foreach ($dependencies as $dependency) {
            if (! ($this->selectedEntities[$dependency] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get dependencies that are missing for an entity.
     *
     * @return array<string>
     */
    public function getMissingDependencies(string $entity): array
    {
        $entities = $this->getEntities();
        $dependencies = $entities[$entity]['dependencies'] ?? [];

        return array_filter($dependencies, fn (string $dep): bool => ! ($this->selectedEntities[$dep] ?? false));
    }

    /**
     * Toggle entity selection with dependency validation.
     */
    public function toggleEntity(string $entity): void
    {
        if ($this->selectedEntities[$entity]) {
            // Unselecting - also unselect entities that depend on this one
            $this->selectedEntities[$entity] = false;
            $this->cascadeUnselect($entity);
        } elseif ($this->canSelectEntity($entity)) {
            $this->selectedEntities[$entity] = true;
        }
    }

    /**
     * Cascade unselect for entities that depend on the given entity.
     */
    private function cascadeUnselect(string $entity): void
    {
        $entities = $this->getEntities();

        foreach ($entities as $key => $config) {
            if (in_array($entity, $config['dependencies'], true) && $this->selectedEntities[$key]) {
                $this->selectedEntities[$key] = false;
                $this->cascadeUnselect($key);
            }
        }
    }

    /**
     * Check if any entities are selected.
     */
    public function hasSelectedEntities(): bool
    {
        return count($this->getImportOrder()) > 0;
    }

    /**
     * Move to the next step.
     */
    public function nextStep(): void
    {
        if ($this->currentStep === 1 && $this->hasSelectedEntities()) {
            $this->startMigrationBatch();
            $this->currentStep = 2;
            $this->currentEntity = $this->getImportOrder()[0] ?? null;
        } elseif ($this->currentStep === 2) {
            $this->moveToNextEntity();
        }
    }

    /**
     * Move to the previous step.
     */
    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    /**
     * Start or reuse a migration batch.
     *
     * If an existing in-progress batch exists for this user/team,
     * it will be reset and reused instead of creating a new one.
     */
    private function startMigrationBatch(): void
    {
        $team = Filament::getTenant();

        $batch = MigrationBatch::getOrCreateForMigration(
            userId: (int) auth()->id(),
            teamId: $team?->getKey(),
            entityOrder: $this->getImportOrder(),
        );

        $this->batchId = $batch->id;
    }

    /**
     * Cancel the current migration and reset the wizard.
     *
     * The batch remains in_progress and will be reused on next migration start.
     */
    public function cancelMigration(): void
    {
        $this->resetWizard();
    }

    /**
     * Record import completion for current entity and move to next.
     */
    public function recordImportComplete(int $importedCount, int $failedCount): void
    {
        if ($this->currentEntity) {
            $this->importResults[$this->currentEntity] = [
                'imported' => $importedCount,
                'failed' => $failedCount,
            ];
        }

        $this->moveToNextEntity();
    }

    /**
     * Skip current entity and move to next.
     */
    public function skipCurrentEntity(): void
    {
        if ($this->currentEntity) {
            $this->importResults[$this->currentEntity] = [
                'imported' => 0,
                'failed' => 0,
                'skipped' => true,
            ];
        }

        $this->moveToNextEntity();
    }

    /**
     * Move to the next entity or finish.
     */
    private function moveToNextEntity(): void
    {
        $order = $this->getImportOrder();
        $currentIndex = array_search($this->currentEntity, $order, true);

        if ($currentIndex !== false) {
            $nextIndex = (int) $currentIndex + 1;
            if (isset($order[$nextIndex])) {
                $this->currentEntity = $order[$nextIndex];

                return;
            }
        }

        $this->finishMigration();
    }

    /**
     * Finish the migration batch.
     */
    private function finishMigration(): void
    {
        if ($this->batchId) {
            $batch = MigrationBatch::find($this->batchId);
            if ($batch) {
                $batch->update([
                    'status' => MigrationBatch::STATUS_COMPLETED,
                    'stats' => $this->importResults,
                    'completed_at' => now(),
                ]);
            }
        }

        $this->currentStep = 3;
        $this->currentEntity = null;
    }

    /**
     * Get total counts for summary.
     *
     * @return array{imported: int, failed: int, skipped: int}
     */
    public function getTotalCounts(): array
    {
        $imported = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->importResults as $result) {
            $imported += $result['imported'];
            $failed += $result['failed'];
            if (isset($result['skipped']) && $result['skipped']) {
                $skipped++;
            }
        }

        return ['imported' => $imported, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Get recent imports for current batch.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Import>
     */
    public function getRecentImports(): \Illuminate\Database\Eloquent\Collection
    {
        $team = Filament::getTenant();

        return Import::where('team_id', $team?->getKey())
            ->where('migration_batch_id', $this->batchId)
            ->latest()
            ->get();
    }

    /**
     * Reset the wizard to start over.
     */
    public function resetWizard(): void
    {
        $this->currentStep = 1;
        $this->selectedEntities = [
            'companies' => false,
            'people' => false,
            'opportunities' => false,
            'tasks' => false,
            'notes' => false,
        ];
        $this->batchId = null;
        $this->currentEntity = null;
        $this->importResults = [];
    }

    /**
     * Get the action name for the current entity.
     */
    public function getCurrentImportActionName(): ?string
    {
        if (! $this->currentEntity) {
            return null;
        }

        return 'import'.ucfirst($this->currentEntity);
    }

    /**
     * Import action for companies.
     */
    public function importCompaniesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('companies');
    }

    /**
     * Import action for people.
     */
    public function importPeopleAction(): EnhancedImportAction
    {
        return $this->makeImportAction('people');
    }

    /**
     * Import action for opportunities.
     */
    public function importOpportunitiesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('opportunities');
    }

    /**
     * Import action for tasks.
     */
    public function importTasksAction(): EnhancedImportAction
    {
        return $this->makeImportAction('tasks');
    }

    /**
     * Import action for notes.
     */
    public function importNotesAction(): EnhancedImportAction
    {
        return $this->makeImportAction('notes');
    }

    /**
     * Create an import action for a specific entity type.
     *
     * Imports are queued and processed sequentially per team via WithoutOverlapping
     * middleware in BaseImporter. This ensures dependent entities (People, Opportunities)
     * wait for their dependencies (Companies) to complete before processing.
     */
    private function makeImportAction(string $entityType): EnhancedImportAction
    {
        $config = $this->getEntities()[$entityType];

        return EnhancedImportAction::make("import_{$entityType}")
            ->importer($config['importer'])
            ->label("Import {$config['label']}")
            ->modalHeading("Import {$config['label']}")
            ->color('primary')
            ->after(function () use ($entityType): void {
                // Get the latest import for this entity type to record results
                $team = Filament::getTenant();
                $latestImport = Import::where('team_id', $team?->getKey())
                    ->where('importer', $this->getEntities()[$entityType]['importer'])
                    ->latest()
                    ->first();

                if ($latestImport) {
                    // Link import to migration batch
                    if ($this->batchId) {
                        $latestImport->update(['migration_batch_id' => $this->batchId]);
                    }

                    // Record that import was queued - will be processed in order
                    // The queue's WithoutOverlapping middleware ensures sequential processing
                    $this->importResults[$entityType] = [
                        'imported' => 0,
                        'failed' => 0,
                        'processing' => true,
                    ];
                }

                // Immediately advance to next entity - imports will process in order via queue
                $this->moveToNextEntity();
            });
    }

    public function render(): View
    {
        return view('livewire.import.migration-wizard');
    }
}

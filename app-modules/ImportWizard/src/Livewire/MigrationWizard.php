<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\View\View;
use Livewire\Component;
use Relaticle\ImportWizard\Filament\Actions\EnhancedImportAction;
use Relaticle\ImportWizard\Filament\Concerns\HasImportEntities;

/**
 * Multi-entity migration wizard for guided bulk imports.
 *
 * This wizard helps users import data from multiple entities in the correct
 * dependency order, tracking overall progress through a migration batch.
 */
final class MigrationWizard extends Component implements HasActions, HasSchemas
{
    use HasImportEntities;
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

    public ?string $currentEntity = null;

    /** @var array<string, array{imported: int, failed: int, skipped?: bool, processing?: bool, job_failed?: bool}> */
    public array $importResults = [];

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
        return collect($this->getMissingDependencies($entity))->isEmpty();
    }

    /**
     * Get dependencies that are missing for an entity.
     *
     * @return array<string>
     */
    public function getMissingDependencies(string $entity): array
    {
        $dependencies = $this->getEntities()[$entity]['dependencies'] ?? [];

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
        return in_array(true, $this->selectedEntities, true);
    }

    /**
     * Move to the next step.
     */
    public function nextStep(): void
    {
        if ($this->currentStep === 1 && $this->hasSelectedEntities()) {
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
     * Cancel the current migration and reset the wizard.
     */
    public function cancelMigration(): void
    {
        $this->resetWizard();
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
     * Finish the migration and move to completion step.
     */
    private function finishMigration(): void
    {
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
        $results = collect($this->importResults);

        return [
            'imported' => $results->sum('imported'),
            'failed' => $results->sum('failed'),
            'skipped' => $results->where('skipped', true)->count(),
        ];
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
     * Override makeImportAction to add wizard-specific behavior.
     *
     * Imports are queued and processed sequentially per team via WithoutOverlapping
     * middleware in BaseImporter. This ensures dependent entities (People, Opportunities)
     * wait for their dependencies (Companies) to complete before processing.
     */
    protected function makeImportAction(string $entityType): EnhancedImportAction
    {
        $config = $this->getEntities()[$entityType];

        return EnhancedImportAction::make("import_{$entityType}")
            ->importer($config['importer'])
            ->label("Import {$config['label']}")
            ->modalHeading("Import {$config['label']}")
            ->color('primary')
            ->after(function () use ($entityType): void {
                // Record that import was queued - will be processed in order
                // The queue's WithoutOverlapping middleware ensures sequential processing
                $this->importResults[$entityType] = [
                    'imported' => 0,
                    'failed' => 0,
                    'processing' => true,
                ];

                // Immediately advance to next entity - imports will process in order via queue
                $this->moveToNextEntity();
            });
    }

    public function render(): View
    {
        return view('import-wizard::livewire.migration-wizard');
    }
}

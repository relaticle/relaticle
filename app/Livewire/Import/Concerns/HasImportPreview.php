<?php

declare(strict_types=1);

namespace App\Livewire\Import\Concerns;

use App\Data\Import\ImportPreviewResult;
use App\Enums\DuplicateHandlingStrategy;
use App\Services\Import\ImportPreviewService;
use Filament\Facades\Filament;
use Livewire\Attributes\Computed;

/**
 * Provides import preview functionality for the Import Wizard's Preview step.
 *
 * @property ImportPreviewResult|null $previewResult
 */
trait HasImportPreview
{
    /**
     * Generate a preview of what the import will do.
     */
    protected function generateImportPreview(): void
    {
        $importerClass = $this->getImporterClass();
        if ($importerClass === null || $this->persistedFilePath === null) {
            $this->previewResultData = null;

            return;
        }

        $team = Filament::getTenant();
        $user = auth()->user();

        if ($team === null || $user === null) {
            $this->previewResultData = null;

            return;
        }

        $previewService = app(ImportPreviewService::class);

        $result = $previewService->preview(
            importerClass: $importerClass,
            csvPath: $this->persistedFilePath,
            columnMap: $this->columnMap,
            options: [
                'duplicate_handling' => $this->duplicateHandling,
            ],
            teamId: $team->getKey(),
            userId: $user->getAuthIdentifier(),
            valueCorrections: $this->valueCorrections,
        );

        // Store as serializable array data
        $this->previewResultData = $result->toArray();
    }

    /**
     * Get preview result as DTO (computed from stored array data).
     */
    #[Computed]
    public function previewResult(): ?ImportPreviewResult
    {
        if ($this->previewResultData === null) {
            return null;
        }

        return ImportPreviewResult::from($this->previewResultData);
    }

    /**
     * Check if the preview indicates any records will be imported.
     */
    public function hasRecordsToImport(): bool
    {
        $preview = $this->previewResult;
        if ($preview === null) {
            return false;
        }

        return $preview->createCount > 0 || $preview->updateCount > 0;
    }

    /**
     * Get duplicate handling options.
     *
     * @return array<string, string>
     */
    public function getDuplicateHandlingOptions(): array
    {
        $options = [];
        foreach (DuplicateHandlingStrategy::cases() as $strategy) {
            $options[$strategy->value] = $strategy->getLabel();
        }

        return $options;
    }
}

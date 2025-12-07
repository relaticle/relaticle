<?php

declare(strict_types=1);

namespace App\Livewire\Import\Concerns;

use App\Data\Import\ImportPreviewResult;
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
            $this->previewRows = [];

            return;
        }

        $team = Filament::getTenant();
        $user = auth()->user();

        if ($team === null || $user === null) {
            $this->previewResultData = null;
            $this->previewRows = [];

            return;
        }

        $previewService = app(ImportPreviewService::class);

        $result = $previewService->preview(
            importerClass: $importerClass,
            csvPath: $this->persistedFilePath,
            columnMap: $this->columnMap,
            options: [],
            teamId: $team->getKey(),
            userId: $user->getAuthIdentifier(),
            valueCorrections: $this->valueCorrections,
        );

        // Store as serializable array data
        $this->previewResultData = $result->toArray();

        // Store all rows for preview
        $this->previewRows = $result->rows;
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
        return $this->getActiveRowCount() > 0;
    }

    /**
     * Get the count of new records to be created.
     */
    public function getCreateCount(): int
    {
        return collect($this->previewRows)
            ->filter(fn (array $row): bool => $row['_is_new'] ?? true)
            ->count();
    }

    /**
     * Get the count of existing records to be updated.
     */
    public function getUpdateCount(): int
    {
        return collect($this->previewRows)
            ->reject(fn (array $row): bool => $row['_is_new'] ?? true)
            ->count();
    }
}

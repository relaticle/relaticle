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
    private const int PREVIEW_SAMPLE_SIZE = 50;

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

        // Store counts (without rows to keep state small)
        $this->previewResultData = [
            'totalRows' => $result->totalRows,
            'createCount' => $result->createCount,
            'updateCount' => $result->updateCount,
            'rows' => [],
        ];

        // Store only sample rows for preview display (not all 5000+)
        $this->previewRows = array_slice($result->rows, 0, self::PREVIEW_SAMPLE_SIZE);
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
        return ($this->previewResultData['totalRows'] ?? 0) > 0;
    }

    /**
     * Get the count of new records to be created.
     */
    public function getCreateCount(): int
    {
        return $this->previewResultData['createCount'] ?? 0;
    }

    /**
     * Get the count of existing records to be updated.
     */
    public function getUpdateCount(): int
    {
        return $this->previewResultData['updateCount'] ?? 0;
    }
}

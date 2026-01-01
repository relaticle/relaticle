<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Data\ImportPreviewResult;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Jobs\ProcessImportPreview;
use Relaticle\ImportWizard\Services\ImportRecordResolver;
use Relaticle\ImportWizard\Services\PreviewChunkService;

/**
 * Provides import preview functionality for the Import Wizard's Preview step.
 *
 * Uses a hybrid approach:
 * - First batch processed synchronously for instant feedback
 * - Remaining rows processed in background job
 * - Progress tracked via Cache
 *
 * @property ImportPreviewResult|null $previewResult
 */
trait HasImportPreview
{
    private const int INITIAL_BATCH_SIZE = 50;

    /**
     * Generate a preview of what the import will do.
     *
     * Processes first batch synchronously, then dispatches background job for the rest.
     */
    protected function generateImportPreview(): void
    {
        $importerClass = $this->getImporterClass();
        if ($importerClass === null || $this->persistedFilePath === null || $this->sessionId === null) {
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

        $teamId = $team->getKey();
        $userId = $user->getAuthIdentifier();
        $options = ['duplicate_handling' => DuplicateHandlingStrategy::SKIP];

        // Set up the enriched CSV path
        $enrichedPath = Storage::disk('local')->path("temp-imports/{$this->sessionId}/enriched.csv");

        // Pre-load records for fast lookups
        $recordResolver = app(ImportRecordResolver::class);
        $recordResolver->loadForTeam($teamId, $importerClass);

        // SYNC: Process first batch immediately
        $service = app(PreviewChunkService::class);
        $initialBatchSize = min(self::INITIAL_BATCH_SIZE, $this->rowCount);

        $firstBatch = $service->processChunk(
            importerClass: $importerClass,
            csvPath: $this->persistedFilePath,
            startRow: 0,
            limit: $initialBatchSize,
            columnMap: $this->columnMap,
            options: $options,
            teamId: $teamId,
            userId: $userId,
            valueCorrections: $this->valueCorrections,
            recordResolver: $recordResolver,
        );

        // Write header + first batch to enriched CSV
        $this->writeInitialEnrichedCsv($enrichedPath, $firstBatch['rows'], $service);

        // Store rows for immediate display
        $this->previewRows = $firstBatch['rows'];

        // Store team ownership for API validation
        Cache::put(
            "import:{$this->sessionId}:team",
            $teamId,
            now()->addHours($this->sessionTtlHours())
        );

        // Set initial progress
        $this->setPreviewProgress(
            processed: $initialBatchSize,
            creates: $firstBatch['creates'],
            updates: $firstBatch['updates'],
        );

        // Store metadata
        $this->previewResultData = [
            'totalRows' => $this->rowCount,
            'createCount' => $firstBatch['creates'],
            'updateCount' => $firstBatch['updates'],
            'rows' => [],
            'isSampled' => false,
            'sampleSize' => $this->rowCount,
        ];

        // ASYNC: Dispatch job for remaining rows
        if ($this->rowCount > $initialBatchSize) {
            Cache::put(
                "import:{$this->sessionId}:status",
                'processing',
                now()->addHours($this->sessionTtlHours())
            );

            ProcessImportPreview::dispatch(
                sessionId: $this->sessionId,
                csvPath: $this->persistedFilePath,
                enrichedPath: $enrichedPath,
                importerClass: $importerClass,
                columnMap: $this->columnMap,
                options: $options,
                teamId: $teamId,
                userId: $userId,
                startRow: $initialBatchSize,
                totalRows: $this->rowCount,
                initialCreates: $firstBatch['creates'],
                initialUpdates: $firstBatch['updates'],
                valueCorrections: $this->valueCorrections,
            );
        } else {
            // Small file - already done
            Cache::put(
                "import:{$this->sessionId}:status",
                'ready',
                now()->addHours($this->sessionTtlHours())
            );
        }
    }

    /**
     * Write the initial enriched CSV with header and first batch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeInitialEnrichedCsv(string $path, array $rows, PreviewChunkService $service): void
    {
        $writer = Writer::createFromPath($path, 'w');

        $writer->insertOne($service->getEnrichedHeaders($this->columnMap));

        foreach ($rows as $row) {
            $writer->insertOne($service->rowToArray($row, $this->columnMap));
        }
    }

    /**
     * Set preview progress in cache.
     */
    private function setPreviewProgress(int $processed, int $creates, int $updates): void
    {
        Cache::put(
            "import:{$this->sessionId}:progress",
            [
                'processed' => $processed,
                'creates' => $creates,
                'updates' => $updates,
                'total' => $this->rowCount,
            ],
            now()->addHours($this->sessionTtlHours())
        );
    }

    /**
     * Get the preview processing status.
     */
    public function getPreviewStatus(): string
    {
        if ($this->sessionId === null) {
            return 'pending';
        }

        return Cache::get("import:{$this->sessionId}:status", 'pending');
    }

    /**
     * Get the preview processing progress.
     *
     * @return array{processed: int, creates: int, updates: int, total: int}
     */
    public function getPreviewProgress(): array
    {
        if ($this->sessionId === null) {
            return [
                'processed' => 0,
                'creates' => 0,
                'updates' => 0,
                'total' => 0,
            ];
        }

        return Cache::get("import:{$this->sessionId}:progress", [
            'processed' => 0,
            'creates' => 0,
            'updates' => 0,
            'total' => $this->rowCount,
        ]);
    }

    /**
     * Check if preview processing is complete.
     */
    public function isPreviewReady(): bool
    {
        return $this->getPreviewStatus() === 'ready';
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

        // Update counts from cache if processing
        $progress = $this->getPreviewProgress();
        $this->previewResultData['createCount'] = $progress['creates'];
        $this->previewResultData['updateCount'] = $progress['updates'];

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
        $progress = $this->getPreviewProgress();

        return $progress['creates'];
    }

    /**
     * Get the count of existing records to be updated.
     */
    public function getUpdateCount(): int
    {
        $progress = $this->getPreviewProgress();

        return $progress['updates'];
    }

    private function sessionTtlHours(): int
    {
        return (int) config('import-wizard.session_ttl_hours', 24);
    }
}

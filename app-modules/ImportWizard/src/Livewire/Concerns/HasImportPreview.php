<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use Livewire\Attributes\Computed;
use Relaticle\ImportWizard\Data\ImportPreviewResult;
use Relaticle\ImportWizard\Data\ImportSessionData;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Enums\PreviewStatus;
use Relaticle\ImportWizard\Jobs\ProcessImportPreview;
use Relaticle\ImportWizard\Services\ImportRecordResolver;
use Relaticle\ImportWizard\Services\PreviewChunkService;

/** @property ImportPreviewResult|null $previewResult */
trait HasImportPreview
{
    private const int INITIAL_BATCH_SIZE = 50;

    public ?string $previewInputHash = null;

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

        $newHash = $this->computePreviewInputHash();

        // Skip if no changes since last preview
        if ($this->previewInputHash === $newHash && $this->previewResultData !== null) {
            return;
        }

        $teamId = $team->getKey();
        $userId = $user->getAuthIdentifier();
        $options = ['duplicate_handling' => DuplicateHandlingStrategy::SKIP];

        $this->previewInputHash = $newHash;

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

        // Initialize consolidated session cache
        new ImportSessionData(
            teamId: $teamId,
            inputHash: $newHash,
            total: $this->rowCount,
            processed: $initialBatchSize,
            creates: $firstBatch['creates'],
            updates: $firstBatch['updates'],
            heartbeat: (int) now()->timestamp,
        )->save($this->sessionId);

        // Store metadata for component
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
                inputHash: $newHash,
                valueCorrections: $this->valueCorrections,
            );
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeInitialEnrichedCsv(string $path, array $rows, PreviewChunkService $service): void
    {
        $writer = Writer::createFromPath($path, 'w');

        $writer->insertOne($service->getEnrichedHeaders($this->columnMap));

        foreach ($rows as $row) {
            $writer->insertOne($service->rowToArray($row, $this->columnMap));
        }
    }

    public function getPreviewStatus(): PreviewStatus
    {
        return $this->sessionId !== null
            ? (ImportSessionData::find($this->sessionId)?->status() ?? PreviewStatus::Pending)
            : PreviewStatus::Pending;
    }

    /** @return array{processed: int, creates: int, updates: int, total: int} */
    public function getPreviewProgress(): array
    {
        $data = $this->sessionId !== null ? ImportSessionData::find($this->sessionId) : null;

        return $data instanceof ImportSessionData
            ? ['processed' => $data->processed, 'creates' => $data->creates, 'updates' => $data->updates, 'total' => $data->total]
            : ['processed' => 0, 'creates' => 0, 'updates' => 0, 'total' => $this->rowCount];
    }

    public function isPreviewReady(): bool
    {
        return $this->getPreviewStatus() === PreviewStatus::Ready;
    }

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

    public function hasRecordsToImport(): bool
    {
        return ($this->previewResultData['totalRows'] ?? 0) > 0;
    }

    public function getCreateCount(): int
    {
        return $this->getPreviewProgress()['creates'];
    }

    public function getUpdateCount(): int
    {
        return $this->getPreviewProgress()['updates'];
    }

    private function computePreviewInputHash(): string
    {
        return md5(json_encode([
            'columnMap' => $this->columnMap,
            'valueCorrections' => $this->valueCorrections,
        ], JSON_THROW_ON_ERROR));
    }
}

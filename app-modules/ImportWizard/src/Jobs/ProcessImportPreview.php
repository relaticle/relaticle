<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use League\Csv\Writer;
use Relaticle\ImportWizard\Data\ImportSessionData;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Support\ImportRecordResolver;
use Relaticle\ImportWizard\Support\PreviewChunkService;

/**
 * Background job to process remaining import preview rows.
 *
 * The first batch is processed synchronously for instant feedback.
 * This job handles the remaining rows asynchronously.
 *
 * Automatically stops processing (without deleting files) when:
 * - Cache is cleared (user cancelled/reset)
 * - input_hash changes (user regenerated preview)
 * - heartbeat is stale (user navigated away or closed browser)
 *
 * File cleanup is handled by cleanupTempFile() or scheduled command.
 */
final class ProcessImportPreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int CHUNK_SIZE = 500;

    private const int HEARTBEAT_TIMEOUT_SECONDS = 30;

    /**
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, string>>  $valueCorrections
     * @param  array<string>  $initialNewCompanyNames
     */
    public function __construct(
        public string $sessionId,
        public string $csvPath,
        public string $enrichedPath,
        public string $importerClass,
        public array $columnMap,
        public array $options,
        public string $teamId,
        public string $userId,
        public int $startRow,
        public int $totalRows,
        public int $initialCreates,
        public int $initialUpdates,
        public string $inputHash,
        public array $valueCorrections = [],
        public array $initialNewCompanyNames = [],
    ) {
        $this->onQueue('imports');
    }

    public function handle(PreviewChunkService $service): void
    {
        $processed = $this->startRow;
        $creates = $this->initialCreates;
        $updates = $this->initialUpdates;

        // Track unique new company names across all chunks
        $newCompanyNames = array_flip($this->initialNewCompanyNames);

        // Pre-load records for fast lookups
        $recordResolver = resolve(ImportRecordResolver::class);
        $recordResolver->loadForTeam($this->teamId, $this->importerClass);

        // Open enriched CSV for appending
        $writer = Writer::createFromPath($this->enrichedPath, 'a');

        try {
            while ($processed < $this->totalRows) {
                // Check if we should stop before each chunk
                // Note: We don't cleanup here - user might navigate between steps
                // Files are cleaned up by cleanupTempFile() or scheduled command
                if ($this->shouldStop()) {
                    return;
                }

                $limit = min(self::CHUNK_SIZE, $this->totalRows - $processed);

                $result = $service->processChunk(
                    importerClass: $this->importerClass,
                    csvPath: $this->csvPath,
                    startRow: $processed,
                    limit: $limit,
                    columnMap: $this->columnMap,
                    options: $this->options,
                    teamId: $this->teamId,
                    userId: $this->userId,
                    valueCorrections: $this->valueCorrections,
                    recordResolver: $recordResolver,
                );

                // Write rows to CSV
                foreach ($result['rows'] as $row) {
                    $writer->insertOne($service->rowToArray($row, $this->columnMap));
                }

                $creates += $result['creates'];
                $updates += $result['updates'];
                $processed += $limit;

                // Accumulate unique new company names
                foreach ($result['newCompanyNames'] as $companyName) {
                    $newCompanyNames[$companyName] = true;
                }

                // Update progress in consolidated cache
                $this->updateProgress($processed, $creates, $updates, count($newCompanyNames));
            }

            // Processing complete - status will be derived as 'ready' since processed >= total
        } catch (\Throwable $e) {
            report($e);

            // Mark as failed in cache
            $this->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    private function shouldStop(): bool
    {
        $data = ImportSessionData::find($this->sessionId);

        if (! $data instanceof ImportSessionData) {
            return true;
        }

        return $data->inputHash !== $this->inputHash || $data->isHeartbeatStale(self::HEARTBEAT_TIMEOUT_SECONDS);
    }

    private function updateProgress(int $processed, int $creates, int $updates, int $newCompanies): void
    {
        ImportSessionData::update($this->sessionId, [
            'processed' => $processed,
            'creates' => $creates,
            'updates' => $updates,
            'new_companies' => $newCompanies,
        ]);
    }

    private function markAsFailed(string $error): void
    {
        ImportSessionData::update($this->sessionId, ['error' => $error]);
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'import-preview',
            "session:{$this->sessionId}",
            "team:{$this->teamId}",
        ];
    }
}

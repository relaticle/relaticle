<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use League\Csv\Writer;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Services\ImportRecordResolver;
use Relaticle\ImportWizard\Services\PreviewChunkService;

/**
 * Background job to process remaining import preview rows.
 *
 * The first batch is processed synchronously for instant feedback.
 * This job handles the remaining rows asynchronously.
 */
final class ProcessImportPreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int CHUNK_SIZE = 500;

    /**
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, string>>  $valueCorrections
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
        public array $valueCorrections = [],
    ) {
        $this->onQueue('imports');
    }

    public function handle(PreviewChunkService $service): void
    {
        $processed = $this->startRow;
        $creates = $this->initialCreates;
        $updates = $this->initialUpdates;

        // Pre-load records for fast lookups
        $recordResolver = app(ImportRecordResolver::class);
        $recordResolver->loadForTeam($this->teamId, $this->importerClass);

        // Open enriched CSV for appending
        $writer = Writer::createFromPath($this->enrichedPath, 'a');

        try {
            while ($processed < $this->totalRows) {
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

                // Update progress in cache
                $this->updateProgress($processed, $creates, $updates);
            }

            // Mark as ready
            Cache::put(
                "import:{$this->sessionId}:status",
                'ready',
                now()->addHours($this->ttlHours())
            );
        } catch (\Throwable $e) {
            report($e);

            Cache::put(
                "import:{$this->sessionId}:status",
                'failed',
                now()->addHours($this->ttlHours())
            );

            throw $e;
        }
    }

    /**
     * Update progress in cache.
     */
    private function updateProgress(int $processed, int $creates, int $updates): void
    {
        Cache::put(
            "import:{$this->sessionId}:progress",
            [
                'processed' => $processed,
                'creates' => $creates,
                'updates' => $updates,
                'total' => $this->totalRows,
            ],
            now()->addHours($this->ttlHours())
        );
    }

    private function ttlHours(): int
    {
        return (int) config('import-wizard.session_ttl_hours', 24);
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

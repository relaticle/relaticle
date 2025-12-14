<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Filament\Actions\Imports\Importer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use League\Csv\Statement;
use Relaticle\ImportWizard\Events\ImportChunkProcessed;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Services\CsvReaderFactory;

/**
 * Streaming import job that reads rows on-demand from file instead of serializing data.
 *
 * This significantly reduces queue payload size (500 bytes vs 100KB) by passing
 * row offset/limit instead of the actual data. Rows are streamed from the CSV
 * file during job execution.
 */
final class StreamingImportCsv implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue this job should be dispatched to.
     */
    public string $queue = 'imports';

    /**
     * @param  Import  $import  The import model
     * @param  int  $startRow  Row offset to start reading from (0-indexed)
     * @param  int  $rowCount  Number of rows to process in this chunk
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<string, mixed>  $options  Import options
     */
    public function __construct(
        protected Import $import,
        protected int $startRow,
        protected int $rowCount,
        protected array $columnMap,
        protected array $options = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Stream rows from file on-demand
        $csvPath = Storage::disk('local')->path($this->import->file_path);
        $csvReader = App::make(CsvReaderFactory::class)->createFromPath($csvPath);

        $records = (new Statement)
            ->offset($this->startRow)
            ->limit($this->rowCount)
            ->process($csvReader);

        // Create importer instance
        /** @var Importer $importer */
        $importer = App::make($this->import->importer, [
            'import' => $this->import,
            'columnMap' => $this->columnMap,
            'options' => $this->options,
        ]);

        $processedCount = 0;
        $successCount = 0;
        $failureCount = 0;

        // Process each row
        foreach ($records as $record) {
            try {
                // Use Filament's complete import pipeline
                ($importer)($record);

                $successCount++;
            } catch (\Throwable $e) {
                $failureCount++;
                report($e);
            }

            $processedCount++;
        }

        // Update import model stats
        $this->import->increment('processed_rows', $processedCount);
        $this->import->increment('successful_rows', $successCount);

        // Fire event for progress tracking
        event(new ImportChunkProcessed(
            import: $this->import,
            processedRows: $processedCount,
            successfulRows: $successCount,
            failedRows: $failureCount,
        ));
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Get middleware from the importer
        /** @var Importer $importer */
        $importer = App::make($this->import->importer, [
            'import' => $this->import,
            'columnMap' => $this->columnMap,
            'options' => array_merge($this->options, ['_chunk_size' => $this->rowCount]),
        ]);

        return $importer->getJobMiddleware();
    }
}

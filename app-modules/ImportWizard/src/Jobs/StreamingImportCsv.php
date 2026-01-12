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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Statement;
use Relaticle\CustomFields\Filament\Integration\Support\Imports\ImportColumnConfigurator;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Support\CsvReaderFactory;

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
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * @param  Import  $import  The import model
     * @param  int  $startRow  Row offset to start reading from (0-indexed)
     * @param  int  $rowCount  Number of rows to process in this chunk
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<string, mixed>  $options  Import options
     */
    public function __construct(
        private Import $import,
        private int $startRow,
        private int $rowCount,
        private array $columnMap,
        private array $options = [],
    ) {
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $csvPath = Storage::disk('local')->path($this->import->file_path);

        throw_unless(file_exists($csvPath), \RuntimeException::class, "Import file not found: {$csvPath}");

        // Set runtime flag for creating missing select options
        $createMissingOptions = $this->options['create_missing_select_options'] ?? false;
        ImportColumnConfigurator::setCreateMissingSelectOptions($createMissingOptions);

        try {
            $csvReader = App::make(CsvReaderFactory::class)->createFromPath($csvPath);

            $records = (new Statement)
                ->offset($this->startRow)
                ->limit($this->rowCount)
                ->process($csvReader);

            /** @var Importer $importer */
            $importer = App::make($this->import->importer, [
                'import' => $this->import,
                'columnMap' => $this->columnMap,
                'options' => $this->options,
            ]);

            $processedCount = 0;
            $successCount = 0;

            foreach ($records as $record) {
                try {
                    ($importer)($record);
                    $successCount++;
                } catch (\Throwable $e) {
                    report($e);
                }

                $processedCount++;
            }

            $this->import->increment('processed_rows', $processedCount);
            $this->import->increment('successful_rows', $successCount);
        } finally {
            // Always reset the flag after processing
            ImportColumnConfigurator::setCreateMissingSelectOptions(false);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StreamingImportCsv failed', [
            'import_id' => $this->import->id,
            'start_row' => $this->startRow,
            'row_count' => $this->rowCount,
            'exception' => $exception->getMessage(),
        ]);
    }
}

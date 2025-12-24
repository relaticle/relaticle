<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\SyntaxError;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\ImportWizard\Services\CsvReaderFactory;

/**
 * Provides CSV file parsing functionality for the Import Wizard.
 */
trait HasCsvParsing
{
    /**
     * Parse the uploaded file and extract headers and row count.
     */
    protected function parseUploadedFile(): void
    {
        if ($this->uploadedFile === null) {
            return;
        }

        // Persist the CSV file to storage
        $csvPath = $this->persistFile($this->uploadedFile);
        if ($csvPath === null) {
            return;
        }

        $this->persistedFilePath = $csvPath;

        try {
            // Parse the CSV
            $csvReader = app(CsvReaderFactory::class)->createFromPath($csvPath);
            $this->csvHeaders = $csvReader->getHeader();
            $this->rowCount = $this->fastRowCount($csvPath, $csvReader);

            // Validate row count (max 10,000 rows for performance)
            if ($this->rowCount > 10000) {
                $this->addError(
                    'uploadedFile',
                    'This file contains '.number_format($this->rowCount).' rows. '.
                    'The maximum is 10,000 rows per import. '.
                    'Please split your file into smaller chunks, or contact support for assistance with bulk imports.'
                );
                $this->cleanupTempFile();
                $this->persistedFilePath = null;
                $this->rowCount = 0;

                return;
            }
        } catch (SyntaxError $e) {
            // Handle duplicate column names
            $duplicates = $e->duplicateColumnNames();
            if ($duplicates !== []) {
                $this->addError('uploadedFile', 'Your CSV has duplicate column names: '.implode(', ', $duplicates).'. Please rename them to be unique.');
            } else {
                $this->addError('uploadedFile', 'CSV syntax error: '.$e->getMessage());
            }
            $this->cleanupTempFile();
            $this->persistedFilePath = null;
            $this->rowCount = 0;
        }
    }

    /**
     * Persist the uploaded CSV file to storage.
     */
    protected function persistFile(TemporaryUploadedFile $file): ?string
    {
        try {
            $sourcePath = $file->getRealPath();
            $storagePath = 'temp-imports/'.Str::uuid()->toString().'.csv';

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new \RuntimeException('Failed to read file content');
            }

            Storage::disk('local')->put($storagePath, $content);

            return Storage::disk('local')->path($storagePath);
        } catch (\Exception $e) {
            report($e);
            $this->addError('uploadedFile', 'Failed to process file: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Clean up temporary files.
     */
    protected function cleanupTempFile(): void
    {
        if ($this->persistedFilePath === null) {
            return;
        }

        $storagePath = str_replace(Storage::disk('local')->path(''), '', $this->persistedFilePath);
        if (Storage::disk('local')->exists($storagePath)) {
            Storage::disk('local')->delete($storagePath);
        }
    }

    /**
     * Get preview values for a specific CSV column.
     *
     * @return array<int, string>
     */
    public function getColumnPreviewValues(string $csvColumn, int $limit = 5): array
    {
        if ($this->persistedFilePath === null) {
            return [];
        }

        $csvReader = app(CsvReaderFactory::class)->createFromPath($this->persistedFilePath);

        return collect($csvReader->getRecords())
            ->take($limit)
            ->pluck($csvColumn)
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->toArray();
    }

    /**
     * Fast row counting with file size estimation for large files.
     *
     * For small files (< 1MB), uses exact counting.
     * For large files, samples 100 rows and estimates based on average row size.
     *
     * @param  \League\Csv\Reader<array<string, mixed>>  $csvReader
     */
    private function fastRowCount(string $csvPath, $csvReader): int
    {
        $fileSize = filesize($csvPath);
        if ($fileSize === false) {
            // Fallback to exact count if filesize fails
            return iterator_count($csvReader->getRecords());
        }

        // For small files (< 1MB), exact count is fast
        if ($fileSize < 1_048_576) {
            return iterator_count($csvReader->getRecords());
        }

        // For large files, sample 100 rows and estimate
        $sampleSize = 100;
        $sample = [];
        $iterator = $csvReader->getRecords();
        $count = 0;

        foreach ($iterator as $record) {
            $sample[] = $record;
            $count++;
            if ($count >= $sampleSize) {
                break;
            }
        }

        if ($count === 0) {
            return 0;
        }

        // Get header size (first line)
        $headerContent = '';
        $file = fopen($csvPath, 'r');
        if ($file !== false) {
            $headerContent = fgets($file) ?: '';
            fclose($file);
        }
        $headerBytes = strlen($headerContent);

        // Calculate average row size from sample
        $sampleStartPos = $headerBytes;
        $sampleContent = file_get_contents($csvPath, offset: $sampleStartPos, length: 8192);
        if ($sampleContent === false) {
            // Fallback to exact count if reading fails
            return iterator_count($csvReader->getRecords());
        }

        $sampleLines = explode("\n", trim($sampleContent));
        $avgRowSize = strlen($sampleContent) / max(1, count($sampleLines));

        // Estimate total rows
        $dataSize = $fileSize - $headerBytes;

        return (int) ceil($dataSize / max(1, $avgRowSize));
    }
}

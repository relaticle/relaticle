<?php

declare(strict_types=1);

namespace App\Livewire\Import\Concerns;

use App\Services\Import\ExcelToCsvConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader as CsvReader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Provides CSV/Excel file parsing functionality for the Import Wizard.
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

        // Convert Excel to CSV if needed
        $csvPath = $this->convertAndPersistFile($this->uploadedFile);
        if ($csvPath === null) {
            return;
        }

        $this->persistedFilePath = $csvPath;

        // Parse the CSV
        $csvReader = $this->createCsvReader($csvPath);
        $this->csvHeaders = $csvReader->getHeader();
        $this->rowCount = iterator_count($csvReader->getRecords());
    }

    /**
     * Convert Excel to CSV if needed and persist the file.
     */
    protected function convertAndPersistFile(TemporaryUploadedFile $file): ?string
    {
        $converter = app(ExcelToCsvConverter::class);

        // Create UploadedFile from temporary file
        $uploadedFile = new UploadedFile(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $file->getMimeType(),
        );

        try {
            // Convert Excel to CSV if needed
            if ($converter->isExcelFile($uploadedFile)) {
                $csvFile = $converter->convert($uploadedFile);
                $sourcePath = $csvFile->getRealPath();
            } else {
                $sourcePath = $uploadedFile->getRealPath();
            }

            // Persist to storage
            $storagePath = 'temp-imports/' . Str::uuid()->toString() . '.csv';
            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new \RuntimeException('Failed to read file content');
            }
            Storage::disk('local')->put($storagePath, $content);

            return Storage::disk('local')->path($storagePath);
        } catch (\Exception $e) {
            report($e);
            $this->addError('uploadedFile', 'Failed to process file: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Create a CSV reader with auto-detected delimiter.
     *
     * @return CsvReader<array<string, mixed>>
     */
    protected function createCsvReader(string $csvPath): CsvReader
    {
        $csvReader = CsvReader::createFromPath($csvPath);
        $csvReader->setHeaderOffset(0);

        // Auto-detect delimiter
        $delimiter = $this->detectCsvDelimiter($csvPath);
        if ($delimiter !== null) {
            $csvReader->setDelimiter($delimiter);
        }

        return $csvReader;
    }

    /**
     * Auto-detect CSV delimiter.
     */
    protected function detectCsvDelimiter(string $csvPath): ?string
    {
        $content = file_get_contents($csvPath, length: 1024);
        if ($content === false) {
            return null;
        }

        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($content, $delimiter);
        }

        arsort($counts);
        $detected = array_key_first($counts);

        return $counts[$detected] > 0 ? $detected : null;
    }

    /**
     * Get sample rows from the CSV for preview.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getSampleRows(int $limit = 5): array
    {
        if ($this->persistedFilePath === null) {
            return [];
        }

        $csvReader = $this->createCsvReader($this->persistedFilePath);
        $records = [];
        $count = 0;

        foreach ($csvReader->getRecords() as $record) {
            $records[] = $record;
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $records;
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

        $csvReader = $this->createCsvReader($this->persistedFilePath);
        $values = [];
        $count = 0;

        foreach ($csvReader->getRecords() as $record) {
            if (isset($record[$csvColumn])) {
                $values[] = (string) $record[$csvColumn];
            }
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $values;
    }
}

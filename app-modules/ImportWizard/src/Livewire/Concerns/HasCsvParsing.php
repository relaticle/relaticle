<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\SyntaxError;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\ImportWizard\Services\CsvReaderFactory;
use Relaticle\ImportWizard\Services\ExcelToCsvConverter;

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

        try {
            // Parse the CSV
            $csvReader = app(CsvReaderFactory::class)->createFromPath($csvPath);
            $this->csvHeaders = $csvReader->getHeader();
            $this->rowCount = iterator_count($csvReader->getRecords());

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
}

<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Support\Facades\Cache;
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
            $this->rowCount = iterator_count($csvReader->getRecords());

            // Validate row count
            $maxRows = (int) config('import-wizard.max_rows_per_file', 10000);
            if ($this->rowCount > $maxRows) {
                $this->addError(
                    'uploadedFile',
                    'This file contains '.number_format($this->rowCount).' rows. '.
                    'The maximum is '.number_format($maxRows).' rows per import. '.
                    'Please split your file into smaller chunks.'
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
     * Persist the uploaded CSV file to storage in a session folder.
     */
    protected function persistFile(TemporaryUploadedFile $file): ?string
    {
        try {
            $this->sessionId = Str::uuid()->toString();
            $folder = "temp-imports/{$this->sessionId}";
            $storagePath = "{$folder}/original.csv";

            $sourcePath = $file->getRealPath();
            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new \RuntimeException('Failed to read file content');
            }

            Storage::disk('local')->makeDirectory($folder);
            Storage::disk('local')->put($storagePath, $content);

            return Storage::disk('local')->path($storagePath);
        } catch (\Exception $e) {
            report($e);
            $this->addError('uploadedFile', 'Failed to process file: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Clean up temporary session folder and cache keys.
     */
    protected function cleanupTempFile(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $folder = "temp-imports/{$this->sessionId}";
        if (Storage::disk('local')->exists($folder)) {
            Storage::disk('local')->deleteDirectory($folder);
        }

        Cache::forget("import:{$this->sessionId}:status");
        Cache::forget("import:{$this->sessionId}:progress");
        Cache::forget("import:{$this->sessionId}:team");
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

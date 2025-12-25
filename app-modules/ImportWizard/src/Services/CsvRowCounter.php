<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use League\Csv\Reader;

/**
 * Service for efficiently counting rows in CSV files.
 *
 * Uses exact counting for small files and estimation
 * based on sampling for larger files to maintain performance.
 */
final class CsvRowCounter
{
    /**
     * Count the number of data rows in a CSV file.
     *
     * @param  Reader<array<string, mixed>>  $csvReader
     */
    public function count(string $csvPath, Reader $csvReader): int
    {
        $fileSize = filesize($csvPath);
        if ($fileSize === false) {
            return $this->exactCount($csvReader);
        }

        $threshold = (int) config('import-wizard.row_count.exact_threshold_bytes', 1_048_576);

        if ($fileSize < $threshold) {
            return $this->exactCount($csvReader);
        }

        return $this->estimatedCount($csvPath, $csvReader);
    }

    /**
     * Get exact row count by iterating through all records.
     *
     * @param  Reader<array<string, mixed>>  $csvReader
     */
    private function exactCount(Reader $csvReader): int
    {
        return iterator_count($csvReader->getRecords());
    }

    /**
     * Estimate row count by sampling rows and calculating average row size.
     *
     * @param  Reader<array<string, mixed>>  $csvReader
     */
    private function estimatedCount(string $csvPath, Reader $csvReader): int
    {
        $sampleSize = (int) config('import-wizard.row_count.sample_size', 100);
        $sampleBytes = (int) config('import-wizard.row_count.sample_bytes', 8192);

        // Sample rows to verify the file has content
        $iterator = $csvReader->getRecords();
        $count = 0;

        foreach ($iterator as $record) {
            $count++;
            if ($count >= $sampleSize) {
                break;
            }
        }

        if ($count === 0) {
            return 0;
        }

        // Get header size (first line)
        $headerBytes = $this->getHeaderSize($csvPath);

        // Calculate average row size from sample content
        $sampleContent = file_get_contents($csvPath, offset: $headerBytes, length: max(0, $sampleBytes));
        if ($sampleContent === false) {
            return $this->exactCount($csvReader);
        }

        $sampleLines = explode("\n", trim($sampleContent));
        $avgRowSize = strlen($sampleContent) / max(1, count($sampleLines));

        // Estimate total rows
        $fileSize = filesize($csvPath);
        if ($fileSize === false) {
            return $this->exactCount($csvReader);
        }

        $dataSize = $fileSize - $headerBytes;

        return (int) ceil($dataSize / max(1, $avgRowSize));
    }

    /**
     * Get the size of the header line in bytes.
     */
    private function getHeaderSize(string $csvPath): int
    {
        $file = fopen($csvPath, 'r');
        if ($file === false) {
            return 0;
        }

        $headerContent = fgets($file) ?: '';
        fclose($file);

        return strlen($headerContent);
    }
}

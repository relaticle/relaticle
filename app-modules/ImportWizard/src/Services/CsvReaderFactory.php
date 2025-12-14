<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use League\Csv\Reader as CsvReader;

/**
 * Factory for creating CSV readers with auto-detected delimiters.
 *
 * Centralizes CSV parsing configuration to eliminate code duplication
 * across CsvAnalyzer, ImportPreviewService, and ImportWizard.
 */
final class CsvReaderFactory
{
    /** @var array<string, CsvReader<array<string, mixed>>> */
    private static array $readerCache = [];

    /**
     * Create a CSV reader with auto-detected delimiter.
     *
     * @param  bool  $useCache  Whether to cache and reuse readers for the same path
     * @return CsvReader<array<string, mixed>>
     */
    public function createFromPath(string $csvPath, int $headerOffset = 0, bool $useCache = false): CsvReader
    {
        $cacheKey = $csvPath.':'.$headerOffset;

        if ($useCache && isset(self::$readerCache[$cacheKey])) {
            return self::$readerCache[$cacheKey];
        }

        $csvReader = CsvReader::createFromPath($csvPath);
        $csvReader->setHeaderOffset($headerOffset);

        $delimiter = $this->detectDelimiter($csvPath);
        if ($delimiter !== null) {
            $csvReader->setDelimiter($delimiter);
        }

        if ($useCache) {
            self::$readerCache[$cacheKey] = $csvReader;
        }

        return $csvReader;
    }

    /**
     * Clear the reader cache.
     */
    public static function clearCache(): void
    {
        self::$readerCache = [];
    }

    /**
     * Auto-detect CSV delimiter by sampling first 1KB of file.
     */
    private function detectDelimiter(string $csvPath): ?string
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
}

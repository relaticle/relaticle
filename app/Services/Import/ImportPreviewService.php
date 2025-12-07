<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Data\Import\ImportPreviewResult;
use App\Enums\DuplicateHandlingStrategy;
use App\Models\Import;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;

/**
 * Service for previewing import results without actually saving data.
 *
 * Performs a dry-run of the import by calling each importer's resolveRecord()
 * method to determine whether records would be created or updated.
 */
final readonly class ImportPreviewService
{
    private const int SAMPLE_LIMIT = 5;

    /**
     * Generate a preview of what an import will do.
     *
     * @param  class-string<Importer>  $importerClass
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<string, mixed>  $options  Import options (e.g., duplicate_handling)
     * @param  array<string, array<string, string>>  $valueCorrections  User-defined value corrections
     */
    public function preview(
        string $importerClass,
        string $csvPath,
        array $columnMap,
        array $options,
        int $teamId,
        int $userId,
        array $valueCorrections = [],
    ): ImportPreviewResult {
        // Create a non-persisted Import model for the importer
        $import = new Import;
        $import->setAttribute('team_id', $teamId);
        $import->setAttribute('user_id', $userId);

        $csvReader = $this->createReader($csvPath);
        $records = (new Statement)->process($csvReader);
        $totalRows = iterator_count($records);

        // Reset iterator
        $records = (new Statement)->process($csvReader);

        $willCreate = 0;
        $willUpdate = 0;
        $willSkip = 0;
        $willFail = 0;
        $sampleCreates = [];
        $sampleUpdates = [];
        $errors = [];

        $rowNumber = 0;
        foreach ($records as $record) {
            $rowNumber++;

            // Apply value corrections
            $record = $this->applyCorrections($record, $columnMap, $valueCorrections);

            try {
                $result = $this->previewRow(
                    importerClass: $importerClass,
                    import: $import,
                    columnMap: $columnMap,
                    options: $options,
                    rowData: $record,
                );

                if ($result['action'] === 'create') {
                    $willCreate++;
                    if (count($sampleCreates) < self::SAMPLE_LIMIT) {
                        $sampleCreates[] = $this->formatSampleRecord($record, $columnMap);
                    }
                } elseif ($result['action'] === 'update') {
                    $willUpdate++;
                    if (count($sampleUpdates) < self::SAMPLE_LIMIT) {
                        $sampleUpdates[] = $this->formatSampleRecord($record, $columnMap);
                    }
                } elseif ($result['action'] === 'skip') {
                    $willSkip++;
                }
            } catch (\Throwable $e) {
                $willFail++;
                if (count($errors) < 20) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return new ImportPreviewResult(
            totalRows: $totalRows,
            createCount: $willCreate,
            updateCount: $willUpdate,
            skipCount: $willSkip,
            errorCount: $willFail,
            sampleCreates: $sampleCreates,
            sampleUpdates: $sampleUpdates,
            errors: $errors,
        );
    }

    /**
     * Preview a single row to determine what action would be taken.
     *
     * @param  class-string<Importer>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $rowData
     * @return array{action: string, record: Model|null}
     */
    private function previewRow(
        string $importerClass,
        Import $import,
        array $columnMap,
        array $options,
        array $rowData,
    ): array {
        /** @var Importer $importer */
        $importer = App::make($importerClass, [
            'import' => $import,
            'columnMap' => $columnMap,
            'options' => $options,
        ]);

        // Use reflection to call protected methods without triggering side effects
        $reflection = new \ReflectionClass($importer);

        // Set the data on the importer
        $originalDataProp = $reflection->getProperty('originalData');
        $originalDataProp->setValue($importer, $rowData);

        $dataProp = $reflection->getProperty('data');
        $dataProp->setValue($importer, $rowData);

        // Call remapData to map CSV columns to importer columns
        $remapMethod = $reflection->getMethod('remapData');
        $remapMethod->invoke($importer);

        // Call castData to apply type casting
        $castMethod = $reflection->getMethod('castData');
        $castMethod->invoke($importer);

        // Call resolveRecord to determine create vs update
        // This queries the database but doesn't save anything
        $resolveMethod = $reflection->getMethod('resolveRecord');
        /** @var Model|null $record */
        $record = $resolveMethod->invoke($importer);

        if ($record === null) {
            return ['action' => 'skip', 'record' => null];
        }

        // Check duplicate handling strategy
        $duplicateStrategy = $options['duplicate_handling'] ?? DuplicateHandlingStrategy::SKIP;
        if (is_string($duplicateStrategy)) {
            $duplicateStrategy = DuplicateHandlingStrategy::tryFrom($duplicateStrategy) ?? DuplicateHandlingStrategy::SKIP;
        }

        if ($record->exists) {
            // Record exists in database
            if ($duplicateStrategy === DuplicateHandlingStrategy::SKIP) {
                return ['action' => 'skip', 'record' => $record];
            }

            return ['action' => 'update', 'record' => $record];
        }

        // New record would be created
        return ['action' => 'create', 'record' => $record];
    }

    /**
     * Apply value corrections to a row.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $columnMap
     * @param  array<string, array<string, string>>  $corrections  Map of field name => [old_value => new_value]
     * @return array<string, mixed>
     */
    private function applyCorrections(array $record, array $columnMap, array $corrections): array
    {
        foreach ($corrections as $fieldName => $valueMappings) {
            $csvColumn = $columnMap[$fieldName] ?? null;
            if ($csvColumn === null || ! isset($record[$csvColumn])) {
                continue;
            }

            $currentValue = $record[$csvColumn];
            if (isset($valueMappings[$currentValue])) {
                $record[$csvColumn] = $valueMappings[$currentValue];
            }
        }

        return $record;
    }

    /**
     * Format a sample record for preview display.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $columnMap
     * @return array<string, mixed>
     */
    private function formatSampleRecord(array $record, array $columnMap): array
    {
        $formatted = [];

        foreach ($columnMap as $fieldName => $csvColumn) {
            if ($csvColumn !== '' && isset($record[$csvColumn])) {
                $formatted[$fieldName] = $record[$csvColumn];
            }
        }

        return $formatted;
    }

    /**
     * Create a CSV reader with auto-detected delimiter.
     *
     * @return CsvReader<array<string, mixed>>
     */
    private function createReader(string $csvPath): CsvReader
    {
        $csvReader = CsvReader::createFromPath($csvPath);
        $csvReader->setHeaderOffset(0);

        // Auto-detect delimiter
        $delimiter = $this->detectDelimiter($csvPath);
        if ($delimiter !== null) {
            $csvReader->setDelimiter($delimiter);
        }

        return $csvReader;
    }

    /**
     * Auto-detect CSV delimiter.
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

<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Data\Import\ImportPreviewResult;
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
    /**
     * Generate a preview of what an import will do.
     *
     * @param  class-string<Importer>  $importerClass
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<string, mixed>  $options  Import options
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
        $rows = [];

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

                $isNew = $result['action'] === 'create';

                if ($isNew) {
                    $willCreate++;
                } else {
                    $willUpdate++;
                }

                // Store row data with metadata
                $rows[] = array_merge(
                    $this->formatRowRecord($record, $columnMap),
                    [
                        '_row_index' => $rowNumber,
                        '_is_new' => $isNew,
                    ]
                );
            } catch (\Throwable) {
                // Skip errored rows in preview - they'll be handled during actual import
                continue;
            }
        }

        return new ImportPreviewResult(
            totalRows: $totalRows,
            createCount: $willCreate,
            updateCount: $willUpdate,
            rows: $rows,
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
            return ['action' => 'create', 'record' => null];
        }

        // If record exists in DB, it's an update; otherwise it's a create
        if ($record->exists) {
            return ['action' => 'update', 'record' => $record];
        }

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
     * Format a row record with mapped field names.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $columnMap
     * @return array<string, mixed>
     */
    private function formatRowRecord(array $record, array $columnMap): array
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

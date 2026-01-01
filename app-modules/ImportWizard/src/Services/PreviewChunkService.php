<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use League\Csv\Statement;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Models\Import;

/**
 * Service for processing a chunk of CSV rows for preview.
 *
 * Processes exact row ranges for accurate preview of all rows.
 */
final readonly class PreviewChunkService
{
    public function __construct(
        private CsvReaderFactory $csvReaderFactory,
        private CompanyMatcher $companyMatcher,
    ) {}

    /**
     * Process a chunk of CSV rows and return enriched preview data.
     *
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, string>>  $valueCorrections
     * @return array{rows: array<int, array<string, mixed>>, creates: int, updates: int}
     */
    public function processChunk(
        string $importerClass,
        string $csvPath,
        int $startRow,
        int $limit,
        array $columnMap,
        array $options,
        string $teamId,
        string $userId,
        array $valueCorrections = [],
        ?ImportRecordResolver $recordResolver = null,
    ): array {
        // Create a non-persisted Import model for the importer
        $import = new Import;
        $import->setAttribute('team_id', $teamId);
        $import->setAttribute('user_id', $userId);

        $csvReader = $this->csvReaderFactory->createFromPath($csvPath);

        // Get the specific range of rows
        $records = Statement::create()
            ->offset($startRow)
            ->limit($limit)
            ->process($csvReader);

        $recordResolver ??= tap(app(ImportRecordResolver::class), fn (ImportRecordResolver $r) => $r->loadForTeam($teamId, $importerClass));

        $creates = 0;
        $updates = 0;
        $rows = [];

        $rowNumber = $startRow;
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
                    recordResolver: $recordResolver,
                );

                $isNew = $result['action'] === 'create';

                if ($isNew) {
                    $creates++;
                } else {
                    $updates++;
                }

                // Format row data
                $formattedRow = $this->formatRowRecord($record, $columnMap);

                // Enrich with company match data for People/Opportunity imports
                if ($this->shouldEnrichWithCompanyMatch($importerClass)) {
                    $formattedRow = $this->enrichRowWithCompanyMatch($formattedRow, $teamId);
                }

                // Detect update method
                $hasId = ! blank($formattedRow['id'] ?? null);
                $updateMethod = null;
                $recordId = null;

                if (! $isNew) {
                    $updateMethod = $hasId ? 'id' : 'attribute';
                    $recordId = $result['record']?->getKey();
                }

                $rows[] = array_merge(
                    $formattedRow,
                    [
                        '_row_index' => $rowNumber,
                        '_action' => $isNew ? 'create' : 'update',
                        '_update_method' => $updateMethod,
                        '_record_id' => $recordId,
                    ]
                );
            } catch (\Throwable $e) {
                report($e);

                // Include errored rows with error flag
                $rows[] = [
                    '_row_index' => $rowNumber,
                    '_action' => 'error',
                    '_error' => $e->getMessage(),
                ];
            }
        }

        return [
            'rows' => $rows,
            'creates' => $creates,
            'updates' => $updates,
        ];
    }

    /**
     * Get CSV headers for the enriched CSV file.
     *
     * @param  array<string, string>  $columnMap
     * @return array<int, string>
     */
    public function getEnrichedHeaders(array $columnMap): array
    {
        $headers = ['_row_index', '_action', '_update_method', '_record_id'];

        foreach ($columnMap as $fieldName => $csvColumn) {
            if ($csvColumn !== '') {
                $headers[] = $fieldName;
            }
        }

        return $headers;
    }

    /**
     * Convert a row array to ordered values for CSV writing.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columnMap
     * @return array<int, mixed>
     */
    public function rowToArray(array $row, array $columnMap): array
    {
        $values = [
            $row['_row_index'] ?? '',
            $row['_action'] ?? '',
            $row['_update_method'] ?? '',
            $row['_record_id'] ?? '',
        ];

        foreach ($columnMap as $fieldName => $csvColumn) {
            if ($csvColumn !== '') {
                $values[] = $row[$fieldName] ?? '';
            }
        }

        return $values;
    }

    /**
     * Preview a single row to determine what action would be taken.
     *
     * @param  class-string<BaseImporter>  $importerClass
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
        ImportRecordResolver $recordResolver,
    ): array {
        /** @var BaseImporter $importer */
        $importer = App::make($importerClass, [
            'import' => $import,
            'columnMap' => $columnMap,
            'options' => $options,
        ]);

        $importer->setRecordResolver($recordResolver);

        // Set row data and invoke resolution
        $importer->setRowDataForPreview($rowData);
        $importer->remapData();
        $importer->castData();

        $record = $importer->resolveRecord();

        if (! $record instanceof Model) {
            return ['action' => 'create', 'record' => null];
        }

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
     * @param  array<string, array<string, string>>  $corrections
     * @return array<string, mixed>
     */
    private function applyCorrections(array $record, array $columnMap, array $corrections): array
    {
        foreach ($corrections as $fieldName => $valueMappings) {
            $csvColumn = $columnMap[$fieldName] ?? null;
            if ($csvColumn === null) {
                continue;
            }
            if (! isset($record[$csvColumn])) {
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
     * Check if the importer should have company match enrichment.
     *
     * @param  class-string<BaseImporter>  $importerClass
     */
    private function shouldEnrichWithCompanyMatch(string $importerClass): bool
    {
        return in_array($importerClass, [
            PeopleImporter::class,
            OpportunityImporter::class,
        ], true);
    }

    /**
     * Enrich a row with company match data.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichRowWithCompanyMatch(array $row, string $teamId): array
    {
        $companyId = (string) ($row['id'] ?? '');
        $companyName = (string) ($row['company_name'] ?? '');
        $emails = $this->extractEmailsFromRow($row);

        $matchResult = $this->companyMatcher->match($companyId, $companyName, $emails, $teamId);

        return array_merge($row, [
            '_company_name' => $matchResult->companyName,
            '_company_match_type' => $matchResult->matchType,
            '_company_match_count' => $matchResult->matchCount,
            '_company_id' => $matchResult->companyId,
        ]);
    }

    /**
     * Extract emails from a row for company matching.
     *
     * @param  array<string, mixed>  $row
     * @return array<string>
     */
    private function extractEmailsFromRow(array $row): array
    {
        $raw = $row['custom_fields_emails'] ?? '';
        if (blank($raw)) {
            return [];
        }

        /** @var array<int, string> $emails */
        $emails = is_string($raw) ? explode(',', $raw) : (array) $raw;

        return array_values(array_filter(
            array_map(trim(...), $emails),
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }
}

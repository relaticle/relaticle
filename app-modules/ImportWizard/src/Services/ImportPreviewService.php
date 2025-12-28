<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use League\Csv\Statement;
use Relaticle\ImportWizard\Data\ImportPreviewResult;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Models\Import;

/**
 * Service for previewing import results without actually saving data.
 *
 * Performs a dry-run of the import by calling each importer's resolveRecord()
 * method to determine whether records would be created or updated.
 */
final readonly class ImportPreviewService
{
    public function __construct(
        private CsvReaderFactory $csvReaderFactory,
        private CompanyMatcher $companyMatcher,
        private CsvRowCounter $csvRowCounter,
    ) {}

    /**
     * Generate a preview of what an import will do.
     *
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap  Maps importer field name to CSV column name
     * @param  array<string, mixed>  $options  Import options
     * @param  array<string, array<string, string>>  $valueCorrections  User-defined value corrections
     * @param  int  $sampleSize  Maximum number of rows to process for preview (default 1,000)
     */
    public function preview(
        string $importerClass,
        string $csvPath,
        array $columnMap,
        array $options,
        string $teamId,
        string $userId,
        array $valueCorrections = [],
        int $sampleSize = 1000,
    ): ImportPreviewResult {
        // Create a non-persisted Import model for the importer
        $import = new Import;
        $import->setAttribute('team_id', $teamId);
        $import->setAttribute('user_id', $userId);

        $csvReader = $this->csvReaderFactory->createFromPath($csvPath);
        $totalRows = $this->csvRowCounter->count($csvPath, $csvReader);

        // Process only sampled rows for preview
        $records = (new Statement)->limit($sampleSize)->process($csvReader);

        // Pre-load all records for fast O(1) lookups (avoids N+1 queries)
        $recordResolver = app(ImportRecordResolver::class);
        $recordResolver->loadForTeam($teamId, $importerClass);

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
                    recordResolver: $recordResolver,
                );

                $isNew = $result['action'] === 'create';

                if ($isNew) {
                    $willCreate++;
                } else {
                    $willUpdate++;
                }

                // Store row data with metadata
                $formattedRow = $this->formatRowRecord($record, $columnMap);

                // Enrich with company match data for People/Opportunity imports
                if ($this->shouldEnrichWithCompanyMatch($importerClass)) {
                    $formattedRow = $this->enrichRowWithCompanyMatch($formattedRow, $teamId);
                }

                // Detect update method (ID-based or attribute-based)
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
                        '_is_new' => $isNew,
                        '_update_method' => $updateMethod,
                        '_record_id' => $recordId,
                    ]
                );
            } catch (\Throwable $e) {
                report($e);

                // Skip errored rows in preview - they'll be handled during actual import
                continue;
            }
        }

        $actualSampleSize = min($totalRows, $sampleSize);
        $isSampled = $totalRows > $sampleSize;

        // Scale counts to full dataset if sampled
        $scaledCreateCount = $willCreate;
        $scaledUpdateCount = $willUpdate;

        if ($isSampled && $actualSampleSize > 0) {
            $scaleFactor = $totalRows / $actualSampleSize;
            $scaledCreateCount = (int) round($willCreate * $scaleFactor);
            $scaledUpdateCount = (int) round($willUpdate * $scaleFactor);
        }

        return new ImportPreviewResult(
            totalRows: $totalRows,
            createCount: $scaledCreateCount,
            updateCount: $scaledUpdateCount,
            rows: $rows,
            isSampled: $isSampled,
            sampleSize: $actualSampleSize,
        );
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

        // Set resolver for fast preview lookups (avoids per-row database queries)
        $importer->setRecordResolver($recordResolver);

        // Invoke importer's resolution logic using public Filament APIs
        $record = $this->invokeImporterResolution($importer, $rowData);

        if (! $record instanceof \Illuminate\Database\Eloquent\Model) {
            return ['action' => 'create', 'record' => null];
        }

        // If record exists in DB, it's an update; otherwise it's a create
        if ($record->exists) {
            return ['action' => 'update', 'record' => $record];
        }

        return ['action' => 'create', 'record' => $record];
    }

    /**
     * Invoke the importer's record resolution logic using public Filament APIs.
     *
     * Uses BaseImporter::setRowDataForPreview() to set the row data, then calls
     * Filament's public remapData(), castData(), and resolveRecord() methods.
     *
     * @param  array<string, mixed>  $rowData
     */
    private function invokeImporterResolution(BaseImporter $importer, array $rowData): ?Model
    {
        // Set row data via our public method
        $importer->setRowDataForPreview($rowData);

        // Process through Filament's public pipeline methods
        $importer->remapData();
        $importer->castData();

        // Resolve record (queries DB but doesn't save)
        return $importer->resolveRecord();
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
     * Enrich a row with company match data for transparent preview.
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
        $emailsRaw = $row['custom_fields_emails'] ?? null;

        if ($emailsRaw === null || $emailsRaw === '') {
            return [];
        }

        $emails = is_string($emailsRaw)
            ? array_map(trim(...), explode(',', $emailsRaw))
            : (array) $emailsRaw;

        return array_values(array_filter(
            $emails,
            static fn (mixed $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        ));
    }
}

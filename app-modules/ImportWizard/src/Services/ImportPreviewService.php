<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use League\Csv\Statement;
use Relaticle\ImportWizard\Data\ImportPreviewResult;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Models\Import;

/**
 * Service for previewing import results without actually saving data.
 *
 * Performs a dry-run of the import by calling each importer's resolveRecord()
 * method to determine whether records would be created or updated.
 */
final class ImportPreviewService
{
    /** @var \ReflectionClass<Importer>|null */
    private ?\ReflectionClass $cachedReflectionClass = null;

    /** @var array<string, \ReflectionProperty> */
    private array $cachedReflectionProperties = [];

    /** @var array<string, \ReflectionMethod> */
    private array $cachedReflectionMethods = [];

    private ?string $cachedImporterClass = null;

    public function __construct(
        private readonly CsvReaderFactory $csvReaderFactory,
        private readonly CompanyMatcher $companyMatcher,
    ) {}

    /**
     * Generate a preview of what an import will do.
     *
     * @param  class-string<Importer>  $importerClass
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
        $totalRows = $this->fastRowCount($csvPath, $csvReader);

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
            } catch (\Throwable) {
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
        ImportRecordResolver $recordResolver,
    ): array {
        /** @var Importer $importer */
        $importer = App::make($importerClass, [
            'import' => $import,
            'columnMap' => $columnMap,
            'options' => $options,
        ]);

        // Set resolver for fast preview lookups (avoids per-row database queries)
        if (method_exists($importer, 'setRecordResolver')) {
            $importer->setRecordResolver($recordResolver);
        }

        // Invoke importer's resolution logic using reflection
        /** @var Model|null $record */
        $record = $this->invokeImporterResolution($importer, $rowData);

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
     * Invoke Filament importer's record resolution logic via reflection.
     *
     * WARNING: This method uses reflection to access Filament's internal APIs.
     * If Filament's internal structure changes, this may break.
     *
     * Maintenance: If this breaks after a Filament upgrade, check:
     * 1. Property names: originalData, data
     * 2. Method names: remapData, castData, resolveRecord
     * 3. Consider requesting a public API from Filament for dry-run imports
     *
     * @param  array<string, mixed>  $rowData
     *
     * @throws \RuntimeException If reflection fails (Filament internals changed)
     */
    private function invokeImporterResolution(Importer $importer, array $rowData): ?Model
    {
        try {
            // Use cached reflection objects for performance
            $reflection = $this->getCachedReflectionClass($importer);

            // Set row data on the importer
            $originalDataProp = $this->getCachedReflectionProperty($reflection, 'originalData');
            $originalDataProp->setValue($importer, $rowData);

            $dataProp = $this->getCachedReflectionProperty($reflection, 'data');
            $dataProp->setValue($importer, $rowData);

            // Process the row through importer's pipeline
            $remapMethod = $this->getCachedReflectionMethod($reflection, 'remapData');
            $remapMethod->invoke($importer);

            $castMethod = $this->getCachedReflectionMethod($reflection, 'castData');
            $castMethod->invoke($importer);

            // Resolve record (queries DB but doesn't save)
            $resolveMethod = $this->getCachedReflectionMethod($reflection, 'resolveRecord');

            /** @var Model|null */
            return $resolveMethod->invoke($importer);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException('Failed to invoke Filament importer via reflection. This likely means Filament\'s internal API has changed. '
            .'Please check ImportPreviewService::invokeImporterResolution() and update reflection calls. '
            .'Original error: '.$e->getMessage(), $e->getCode(), previous: $e);
        }
    }

    /**
     * Get or create cached reflection class.
     *
     * @return \ReflectionClass<Importer>
     */
    private function getCachedReflectionClass(Importer $importer): \ReflectionClass
    {
        $importerClass = $importer::class;

        if ($this->cachedImporterClass !== $importerClass || $this->cachedReflectionClass === null) {
            /** @var \ReflectionClass<Importer> $reflection */
            $reflection = new \ReflectionClass($importer);
            $this->cachedReflectionClass = $reflection;
            $this->cachedImporterClass = $importerClass;
            // Clear property and method caches when class changes
            $this->cachedReflectionProperties = [];
            $this->cachedReflectionMethods = [];
        }

        return $this->cachedReflectionClass;
    }

    /**
     * Get or create cached reflection property.
     *
     * @param  \ReflectionClass<Importer>  $reflection
     */
    private function getCachedReflectionProperty(\ReflectionClass $reflection, string $propertyName): \ReflectionProperty
    {
        if (! isset($this->cachedReflectionProperties[$propertyName])) {
            $this->cachedReflectionProperties[$propertyName] = $reflection->getProperty($propertyName);
        }

        return $this->cachedReflectionProperties[$propertyName];
    }

    /**
     * Get or create cached reflection method.
     *
     * @param  \ReflectionClass<Importer>  $reflection
     */
    private function getCachedReflectionMethod(\ReflectionClass $reflection, string $methodName): \ReflectionMethod
    {
        if (! isset($this->cachedReflectionMethods[$methodName])) {
            $this->cachedReflectionMethods[$methodName] = $reflection->getMethod($methodName);
        }

        return $this->cachedReflectionMethods[$methodName];
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
     * @param  class-string<Importer>  $importerClass
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
        $companyName = (string) ($row['company_name'] ?? '');
        $emails = $this->extractEmailsFromRow($row);

        $matchResult = $this->companyMatcher->match($companyName, $emails, $teamId);

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

    /**
     * Fast row counting with file size estimation for large files.
     *
     * For small files (< 1MB), uses exact counting.
     * For large files, samples rows and estimates based on average row size.
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
        $estimatedRows = (int) ceil($dataSize / max(1, $avgRowSize));

        return $estimatedRows;
    }
}

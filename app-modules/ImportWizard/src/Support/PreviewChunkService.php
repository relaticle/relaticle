<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use League\Csv\Statement;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\BaseImporter;
use Relaticle\ImportWizard\Models\Import;

final readonly class PreviewChunkService
{
    public function __construct(
        private CsvReaderFactory $csvReaderFactory,
        private RelationshipPreviewMatcher $relationshipMatcher,
    ) {}

    /**
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, string>>  $valueCorrections
     * @param  array<string, array{csvColumn: string, matcher: string}>  $relationshipMappings
     * @return array{rows: array<int, array<string, mixed>>, creates: int, updates: int, newRelationships: array<string, int>}
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
        array $relationshipMappings = [],
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

        $recordResolver ??= tap(resolve(ImportRecordResolver::class), fn (ImportRecordResolver $r) => $r->loadForTeam($teamId, $importerClass));

        $creates = 0;
        $updates = 0;
        $rows = [];
        /** @var array<string, int> $newRelationships Track counts of new records to be created per relationship */
        $newRelationships = [];

        // Get relationship fields for this importer
        /** @var array<string, RelationshipField> */
        $relationshipFields = $importerClass::getRelationshipFields();

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

                // Detect update method
                $hasId = filled($formattedRow['id'] ?? null);
                $updateMethod = null;
                $recordId = null;

                if (! $isNew) {
                    $updateMethod = $hasId ? 'id' : 'attribute';
                    $recordId = $result['record']?->getKey();
                }

                // Process relationship matches
                $relationshipMatches = $this->processRelationshipMatches(
                    $relationshipFields,
                    $relationshipMappings,
                    $record,
                    $teamId,
                    $newRelationships,
                );

                $rows[] = array_merge(
                    $formattedRow,
                    [
                        '_row_index' => $rowNumber,
                        '_action' => $isNew ? 'create' : 'update',
                        '_update_method' => $updateMethod,
                        '_record_id' => $recordId,
                        '_relationships' => $relationshipMatches,
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
            'newRelationships' => $newRelationships,
        ];
    }

    /**
     * Process relationship matches for a single row.
     *
     * @param  array<string, RelationshipField>  $relationshipFields
     * @param  array<string, array{csvColumn: string, matcher: string}>  $relationshipMappings
     * @param  array<string, mixed>  $rowData
     * @param  array<string, int>  $newRelationships
     * @return array<string, array{matchType: string, matcherUsed: string, matchedName: string|null, icon: string}>
     */
    private function processRelationshipMatches(
        array $relationshipFields,
        array $relationshipMappings,
        array $rowData,
        string $teamId,
        array &$newRelationships,
    ): array {
        $matches = [];

        foreach ($relationshipFields as $relationshipName => $field) {
            $mapping = $relationshipMappings[$relationshipName] ?? [];

            $result = $this->relationshipMatcher->match(
                $field,
                $mapping,
                $rowData,
                $teamId,
            );

            // Track new records to be created
            if ($result->willCreate()) {
                $newRelationships[$relationshipName] = ($newRelationships[$relationshipName] ?? 0) + 1;
            }

            $matches[$relationshipName] = [
                'label' => $result->displayName,
                'matchType' => $result->matchType->value,
                'matcherUsed' => $result->matcherUsed,
                'matchedName' => $result->matchedRecordName,
                'matchedId' => $result->matchedRecordId,
                'icon' => $result->icon,
            ];
        }

        return $matches;
    }

    /**
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
     * @param  class-string<BaseImporter>  $importerClass
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $rowData
     * @return array{action: string, record: Model|null}
     *
     * @throws BindingResolutionException
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

        if ($record->exists) {
            return ['action' => 'update', 'record' => $record];
        }

        return ['action' => 'create', 'record' => $record];
    }

    /**
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
}

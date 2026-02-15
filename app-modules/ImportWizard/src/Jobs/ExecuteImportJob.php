<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\CustomFields\CustomFields;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Filament\Integration\Support\Imports\ImportDataStorage;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Support\SafeValueConverter;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\EntityLinkStorage;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportRow;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkStorage\EntityLinkStorageInterface;

final class ExecuteImportJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    private const string CUSTOM_FIELD_PREFIX = 'custom_fields_';

    /** @var array<string, string> Dedup map for auto-created records: "{entityLinkKey}:{name}" => id */
    private array $createdRecords = [];

    /** @var array<string, string> Matchable value => record ID for intra-import dedup */
    private array $matchableValueCache = [];

    /** @var list<array{row: int, error: string, data?: array<string, mixed>}> */
    private array $failedRows = [];

    /** @var list<int> */
    private array $processedRows = [];

    /** @var list<int> Row numbers promoted from Create to Update by intra-import dedup */
    private array $dedupedRows = [];

    /** @var list<array<string, mixed>> */
    private array $pendingCustomFieldValues = [];

    public function __construct(
        private readonly string $importId,
        private readonly string $teamId,
    ) {}

    public function handle(): void
    {
        $import = Import::query()->findOrFail($this->importId);

        if ($import->team_id !== $this->teamId) {
            return;
        }

        $store = ImportStore::load($this->importId);

        if (! $store instanceof ImportStore) {
            return;
        }

        $store->ensureProcessedColumn();

        $importer = $import->getImporter();
        $mappings = $import->columnMappings();

        $results = [
            'created' => $import->created_rows,
            'updated' => $import->updated_rows,
            'skipped' => $import->skipped_rows,
            'failed' => $import->failed_rows,
        ];
        $allowedKeys = $this->allowedAttributeKeys($importer);
        $customFieldDefs = $this->loadCustomFieldDefinitions($importer);
        $fieldMappings = $mappings->filter(fn (ColumnData $col): bool => $col->isFieldMapping());
        $customFieldFormatMap = $this->buildCustomFieldFormatMap($fieldMappings);

        $matchField = $this->resolveMatchField($importer, $fieldMappings);
        $matchSourceColumn = $matchField instanceof \Relaticle\ImportWizard\Data\MatchableField
            ? $this->findMatchSourceColumn($matchField, $fieldMappings)
            : null;

        $context = [
            'team_id' => $this->teamId,
            'creator_id' => $import->user_id,
        ];

        try {
            $store->query()
                ->where('processed', false)
                ->orderBy('row_number')
                ->chunkById(500, function (Collection $rows) use ($importer, $fieldMappings, $allowedKeys, $customFieldDefs, $customFieldFormatMap, $matchField, $matchSourceColumn, $context, &$results, $store, $import): void {
                    $existingRecords = $this->preloadExistingRecords($rows, $importer);

                    foreach ($rows as $row) {
                        $this->processRow($row, $importer, $fieldMappings, $allowedKeys, $customFieldDefs, $customFieldFormatMap, $matchField, $matchSourceColumn, $context, $results, $existingRecords);
                        $this->flushProcessedRows($store);
                    }
                    $this->flushCustomFieldValues();
                    $this->flushFailedRows($import);
                    $this->persistResults($import, $results);
                });

            $import->update([
                'status' => ImportStatus::Completed,
                'completed_at' => now(),
                'created_rows' => $results['created'],
                'updated_rows' => $results['updated'],
                'skipped_rows' => $results['skipped'],
                'failed_rows' => $results['failed'],
            ]);

            $this->notifyUser($import, $results);
        } catch (\Throwable $e) {
            $this->flushFailedRows($import);
            $this->persistResults($import, $results);
            $import->update(['status' => ImportStatus::Failed]);

            try {
                $this->notifyUser($import, $results, failed: true);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        if (! in_array($import->status, [ImportStatus::Completed, ImportStatus::Failed], true)) {
            $import->update(['status' => ImportStatus::Failed]);
        }

        $this->flushFailedRows($import);

        try {
            $this->notifyUser($import, [
                'created' => $import->created_rows,
                'updated' => $import->updated_rows,
                'skipped' => $import->skipped_rows,
                'failed' => $import->failed_rows,
            ], failed: true);
        } catch (\Throwable) {
        }
    }

    /**
     * @param  Collection<int, ColumnData>  $fieldMappings
     * @param  array<string, true>  $allowedKeys
     * @param  Collection<string, CustomField>  $customFieldDefs
     * @param  array<string, ColumnData>  $customFieldFormatMap
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $results
     * @param  array<string, Model>  $existingRecords
     */
    private function processRow(
        ImportRow $row,
        BaseImporter $importer,
        Collection $fieldMappings,
        array $allowedKeys,
        Collection $customFieldDefs,
        array $customFieldFormatMap,
        ?MatchableField $matchField,
        ?string $matchSourceColumn,
        array $context,
        array &$results,
        array $existingRecords = [],
    ): void {
        if (! $row->isCreate() && ! $row->isUpdate()) {
            $results['skipped']++;
            $this->markProcessed($row);

            return;
        }

        $effectiveAction = $row->match_action;
        $effectiveMatchedId = $row->matched_id;

        if ($effectiveAction === RowMatchAction::Create
            && $matchField instanceof \Relaticle\ImportWizard\Data\MatchableField
            && $matchSourceColumn !== null
        ) {
            $cachedRecordId = $this->lookupMatchableValueCache($row, $matchField, $matchSourceColumn);

            if ($cachedRecordId !== null) {
                $effectiveAction = RowMatchAction::Update;
                $effectiveMatchedId = $cachedRecordId;
                $this->dedupedRows[] = $row->row_number;
            }
        }

        $isCreate = $effectiveAction === RowMatchAction::Create;

        try {
            $data = $this->buildDataFromRow($row, $fieldMappings);
            $existing = $isCreate
                ? (null)
                : $existingRecords[(string) $effectiveMatchedId] ?? $this->findExistingRecord($importer, $effectiveMatchedId);

            if (! $isCreate && ! $existing instanceof Model) {
                $results['skipped']++;
                $this->markProcessed($row);

                return;
            }

            DB::transaction(function () use ($row, $importer, $data, $existing, $context, $isCreate, $matchField, $matchSourceColumn, $allowedKeys, $customFieldDefs, $customFieldFormatMap, &$results): void {
                $pendingRelationships = $this->resolveEntityLinkRelationships($row, $data, $importer, $context);

                $prepared = $importer->prepareForSave($data, $existing, $context);
                $customFieldData = $this->extractCustomFieldData($prepared);
                $prepared = array_intersect_key($prepared, $allowedKeys);

                if (! $isCreate) {
                    unset($prepared['team_id'], $prepared['creator_id'], $prepared['creation_source']);
                    $prepared = array_filter($prepared, filled(...));
                }

                $record = $isCreate
                    ? new ($importer->modelClass())
                    : $existing;

                if ($customFieldData !== []) {
                    ImportDataStorage::setMultiple($record, $customFieldData);
                }

                $record->forceFill($prepared);
                $record->save();

                if ($isCreate && $matchField instanceof \Relaticle\ImportWizard\Data\MatchableField && $matchSourceColumn !== null) {
                    $this->registerInMatchableValueCache($row, $matchField, $matchSourceColumn, (string) $record->getKey());
                }

                $this->storeEntityLinkRelationships($record, $pendingRelationships, $context);

                $results[$isCreate ? 'created' : 'updated']++;

                $storedCustomFieldData = ImportDataStorage::pull($record);
                $batchableData = array_intersect_key($storedCustomFieldData, $customFieldDefs->all());
                $remainingData = array_diff_key($storedCustomFieldData, $batchableData);

                $this->collectCustomFieldValues($record, $batchableData, $customFieldDefs, $customFieldFormatMap, $isCreate);

                if ($remainingData !== []) {
                    ImportDataStorage::setMultiple($record, $remainingData);
                }

                $importer->afterSave($record, $context);
            });

            $this->markProcessed($row);
        } catch (\Throwable $e) {
            $results['failed']++;
            $this->recordFailedRow($row->row_number, $row->raw_data->all(), $e);
            report($e);
        }
    }

    /** @return Collection<string, CustomField> */
    private function loadCustomFieldDefinitions(BaseImporter $importer): Collection
    {
        /** @phpstan-ignore return.type (App\Models\CustomField extends vendor class at runtime via model swapping) */
        return CustomField::query()
            ->withoutGlobalScopes()
            ->with(['options' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $importer->entityName())
            ->where('type', '!=', 'record')
            ->active()
            ->get()
            ->keyBy('code');
    }

    /**
     * @param  array<string, mixed>  $customFieldData
     * @param  Collection<string, CustomField>  $customFieldDefs
     * @param  array<string, ColumnData>  $customFieldFormatMap
     */
    private function collectCustomFieldValues(
        Model $record,
        array $customFieldData,
        Collection $customFieldDefs,
        array $customFieldFormatMap = [],
        bool $isCreate = true,
    ): void {
        if ($customFieldData === []) {
            return;
        }

        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

        foreach ($customFieldData as $code => $value) {
            $cf = $customFieldDefs->get($code);

            if ($cf === null) {
                continue;
            }

            $value = $this->convertCustomFieldValue($value, $cf, $customFieldFormatMap[$code] ?? null);

            $valueColumn = CustomFieldValue::getValueColumn($cf->type);
            $safeValue = SafeValueConverter::toDbSafe($value, $cf->type);

            if (! $isCreate && $cf->typeData->dataType === FieldDataType::MULTI_CHOICE && is_array($safeValue)) {
                $safeValue = $this->mergeWithExistingMultiChoiceValues($record, $cf, $safeValue, $tenantKey);
            }

            $row = [
                'id' => (string) Str::ulid(),
                'entity_type' => $record->getMorphClass(),
                'entity_id' => $record->getKey(),
                'custom_field_id' => $cf->getKey(),
                $tenantKey => $this->teamId,
                'string_value' => null,
                'text_value' => null,
                'integer_value' => null,
                'float_value' => null,
                'json_value' => null,
                'boolean_value' => null,
                'date_value' => null,
                'datetime_value' => null,
            ];

            $row[$valueColumn] = $valueColumn === 'json_value' && $safeValue !== null
                ? json_encode($safeValue)
                : $safeValue;

            $this->pendingCustomFieldValues[] = $row;
        }
    }

    /**
     * @param  array<int, int|string>  $newValues
     * @return array<int, int|string>
     */
    private function mergeWithExistingMultiChoiceValues(
        Model $record,
        CustomField $cf,
        array $newValues,
        string $tenantKey,
    ): array {
        $entityType = $record->getMorphClass();
        $entityId = $record->getKey();
        $cfId = $cf->getKey();

        $existingValues = [];

        foreach ($this->pendingCustomFieldValues as $pending) {
            if ($pending['entity_type'] === $entityType
                && (string) $pending['entity_id'] === (string) $entityId
                && (string) $pending['custom_field_id'] === (string) $cfId
                && $pending['json_value'] !== null
            ) {
                $existingValues = json_decode($pending['json_value'], true) ?? [];
            }
        }

        if ($existingValues === []) {
            $table = config('custom-fields.database.table_names.custom_field_values');
            $dbRow = DB::table($table)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('custom_field_id', $cfId)
                ->where($tenantKey, $this->teamId)
                ->value('json_value');

            if ($dbRow !== null) {
                $existingValues = json_decode($dbRow, true) ?? [];
            }
        }

        if ($existingValues === []) {
            return $newValues;
        }

        return array_values(array_unique([...$existingValues, ...$newValues]));
    }

    private function flushCustomFieldValues(): void
    {
        if ($this->pendingCustomFieldValues === []) {
            return;
        }

        $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
        $table = config('custom-fields.database.table_names.custom_field_values');
        $uniqueBy = ['entity_type', 'entity_id', 'custom_field_id', $tenantKey];
        $updateColumns = [
            'string_value', 'text_value', 'integer_value', 'float_value',
            'json_value', 'boolean_value', 'date_value', 'datetime_value',
        ];

        foreach (array_chunk($this->pendingCustomFieldValues, 500) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }

        $this->pendingCustomFieldValues = [];
    }

    private function markProcessed(ImportRow $row): void
    {
        $this->processedRows[] = $row->row_number;
    }

    private function flushProcessedRows(ImportStore $store): void
    {
        if ($this->dedupedRows !== []) {
            $store->connection()->table('import_rows')
                ->whereIn('row_number', $this->dedupedRows)
                ->update(['match_action' => RowMatchAction::Update->value]);

            $this->dedupedRows = [];
        }

        if ($this->processedRows === []) {
            return;
        }

        $store->connection()->table('import_rows')
            ->whereIn('row_number', $this->processedRows)
            ->update(['processed' => true]);

        $this->processedRows = [];
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     * @return array<string, Model>
     */
    private function preloadExistingRecords(Collection $rows, BaseImporter $importer): array
    {
        $updateIds = $rows
            ->filter(fn (ImportRow $row): bool => $row->isUpdate() && $row->matched_id !== null)
            ->pluck('matched_id')
            ->unique()
            ->all();

        if ($updateIds === []) {
            return [];
        }

        $modelClass = $importer->modelClass();

        return $modelClass::query()
            ->where('team_id', $this->teamId)
            ->whereIn((new $modelClass)->getKeyName(), $updateIds)
            ->get()
            ->keyBy(fn (Model $model): string => (string) $model->getKey())
            ->all();
    }

    /** @param array<string, mixed> $rawData */
    private function recordFailedRow(int $rowNumber, array $rawData, \Throwable $e): void
    {
        $this->failedRows[] = [
            'row' => $rowNumber,
            'error' => Str::limit($e->getMessage(), 500),
            'data' => $rawData,
        ];
    }

    /** @param  array<string, int>  $results */
    private function persistResults(Import $import, array $results): void
    {
        $import->update([
            'created_rows' => $results['created'],
            'updated_rows' => $results['updated'],
            'skipped_rows' => $results['skipped'],
            'failed_rows' => $results['failed'],
        ]);
    }

    private function flushFailedRows(Import $import): void
    {
        if ($this->failedRows === []) {
            return;
        }

        $now = now();

        $rows = collect($this->failedRows)->map(fn (array $row): array => [
            'id' => (string) Str::ulid(),
            'import_id' => $import->id,
            'team_id' => $this->teamId,
            'data' => json_encode($row['data'] ?? ['row_number' => $row['row']]),
            'validation_error' => $row['error'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($rows->chunk(100) as $chunk) {
            $import->failedRows()->insert($chunk->all());
        }

        $this->failedRows = [];
    }

    /**
     * @param  Collection<int, ColumnData>  $fieldMappings
     * @return array<string, mixed>
     */
    private function buildDataFromRow(ImportRow $row, Collection $fieldMappings): array
    {
        return $fieldMappings
            ->mapWithKeys(fn (ColumnData $mapping): array => [
                $mapping->target => $row->getFinalValue($mapping->source),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    private function extractCustomFieldData(array &$prepared): array
    {
        $customFieldData = [];

        foreach ($prepared as $key => $value) {
            if (! str_starts_with($key, self::CUSTOM_FIELD_PREFIX)) {
                continue;
            }

            unset($prepared[$key]);

            if (blank($value)) {
                continue;
            }

            $customFieldData[Str::after($key, self::CUSTOM_FIELD_PREFIX)] = $value;
        }

        return $customFieldData;
    }

    /**
     * Build a map from custom field code to its ColumnData for format lookups.
     *
     * @param  Collection<int, ColumnData>  $fieldMappings
     * @return array<string, ColumnData>
     */
    private function buildCustomFieldFormatMap(Collection $fieldMappings): array
    {
        return $fieldMappings
            ->filter(fn (ColumnData $m): bool => str_starts_with($m->target, self::CUSTOM_FIELD_PREFIX))
            ->mapWithKeys(fn (ColumnData $m): array => [
                Str::after($m->target, self::CUSTOM_FIELD_PREFIX) => $m,
            ])
            ->all();
    }

    /**
     * Apply format-aware conversion for date/datetime and float custom field values.
     */
    private function convertCustomFieldValue(mixed $value, CustomField $cf, ?ColumnData $columnData): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $dataType = $cf->typeData->dataType;

        if ($dataType === FieldDataType::DATE || $dataType === FieldDataType::DATE_TIME) {
            $format = $columnData instanceof ColumnData ? ($columnData->dateFormat ?? DateFormat::ISO) : DateFormat::ISO;
            $parsed = $format->parse($value, $dataType->isTimestamp());

            if (! $parsed instanceof Carbon) {
                return $value;
            }

            return $dataType === FieldDataType::DATE
                ? $parsed->format('Y-m-d')
                : $parsed->format('Y-m-d H:i:s');
        }

        if ($dataType === FieldDataType::FLOAT) {
            $format = $columnData instanceof ColumnData ? ($columnData->numberFormat ?? NumberFormat::POINT) : NumberFormat::POINT;

            return $format->parse($value);
        }

        if ($dataType === FieldDataType::SINGLE_CHOICE) {
            return $this->resolveChoiceValue($cf, $value);
        }

        if ($dataType === FieldDataType::MULTI_CHOICE) {
            return $this->resolveMultiChoiceValue($cf, $value);
        }

        return $value;
    }

    private function resolveChoiceValue(CustomField $cf, string $value): int|string
    {
        $option = $cf->options->firstWhere('name', $value);

        if (! $option) {
            $option = $cf->options->first(
                fn (CustomFieldOption $opt): bool => mb_strtolower((string) $opt->name) === mb_strtolower($value)
            );
        }

        if ($option) {
            $key = $option->getKey();

            return CustomFields::optionModelUsesStringKeys() ? (string) $key : $key;
        }

        $isExistingId = $cf->options->contains(fn (CustomFieldOption $opt): bool => (string) $opt->getKey() === $value);

        if ($isExistingId) {
            return CustomFields::optionModelUsesStringKeys() ? $value : (int) $value;
        }

        return $value;
    }

    /**
     * @return array<int, int|string>
     */
    private function resolveMultiChoiceValue(CustomField $cf, string $value): array
    {
        $items = array_map(trim(...), explode(',', $value));

        if ($cf->typeData->acceptsArbitraryValues) {
            return $items;
        }

        $resolved = [];

        foreach ($items as $item) {
            $resolved[] = $this->resolveChoiceValue($cf, $item);
        }

        return $resolved;
    }

    /** @return array<string, true> */
    private function allowedAttributeKeys(BaseImporter $importer): array
    {
        $keys = collect($importer->allFields())
            ->reject(fn ($field): bool => $field->key === 'id')
            ->pluck('key')
            ->merge(['team_id', 'creator_id', 'creation_source'])
            ->merge(
                collect($importer->entityLinks())
                    ->pluck('foreignKey')
                    ->filter()
            )
            ->all();

        /** @var array<string, true> */
        return array_fill_keys($keys, true);
    }

    private function findExistingRecord(BaseImporter $importer, ?string $matchedId): ?Model
    {
        if ($matchedId === null) {
            return null;
        }

        $modelClass = $importer->modelClass();

        return $modelClass::query()
            ->where('team_id', $this->teamId)
            ->find($matchedId);
    }

    /**
     * Resolve the highest-priority matchable field from the mapped columns.
     *
     * Returns null if no matchable field is mapped or the match behavior is Create-only.
     *
     * @param  Collection<int, ColumnData>  $fieldMappings
     */
    private function resolveMatchField(BaseImporter $importer, Collection $fieldMappings): ?MatchableField
    {
        $mappedFieldKeys = $fieldMappings->pluck('target')->all();
        $matchField = $importer->getMatchFieldForMappedColumns($mappedFieldKeys);

        if (! $matchField instanceof \Relaticle\ImportWizard\Data\MatchableField || $matchField->isCreate()) {
            return null;
        }

        return $matchField;
    }

    /**
     * Find the CSV source column name that maps to the given matchable field.
     *
     * @param  Collection<int, ColumnData>  $fieldMappings
     */
    private function findMatchSourceColumn(MatchableField $matchField, Collection $fieldMappings): ?string
    {
        $mapping = $fieldMappings->first(
            fn (ColumnData $col): bool => $col->target === $matchField->field
        );

        return $mapping?->source;
    }

    private function lookupMatchableValueCache(ImportRow $row, MatchableField $matchField, string $sourceColumn): ?string
    {
        foreach ($this->normalizeMatchableValues($row, $matchField, $sourceColumn) as $normalized) {
            if (isset($this->matchableValueCache[$normalized])) {
                return $this->matchableValueCache[$normalized];
            }
        }

        return null;
    }

    private function registerInMatchableValueCache(ImportRow $row, MatchableField $matchField, string $sourceColumn, string $recordId): void
    {
        foreach ($this->normalizeMatchableValues($row, $matchField, $sourceColumn) as $normalized) {
            $this->matchableValueCache[$normalized] = $recordId;
        }
    }

    /**
     * Extract and normalize the matchable values from a row's source column.
     *
     * For multi-value fields (email, phone), splits on commas.
     * Returns lowercased, trimmed, non-empty strings.
     *
     * @return list<string>
     */
    private function normalizeMatchableValues(ImportRow $row, MatchableField $matchField, string $sourceColumn): array
    {
        $rawValue = $row->getFinalValue($sourceColumn);

        if (blank($rawValue)) {
            return [];
        }

        $parts = $matchField->multiValue
            ? explode(',', (string) $rawValue)
            : [(string) $rawValue];

        return array_values(array_filter(
            array_map(fn (string $part): string => mb_strtolower(trim($part)), $parts),
            fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<int, array{link: EntityLink, strategy: EntityLinkStorageInterface, ids: array<int|string>}>
     */
    private function resolveEntityLinkRelationships(
        ImportRow $row,
        array &$data,
        BaseImporter $importer,
        array $context,
    ): array {
        if ($row->relationships === null || $row->relationships->count() === 0) {
            return [];
        }

        $entityLinks = $importer->entityLinks();
        $pending = [];

        $grouped = collect($row->relationships->all())->groupBy('relationship');

        foreach ($grouped as $linkKey => $matches) {
            $link = $entityLinks[$linkKey] ?? null;

            if ($link === null) {
                continue;
            }

            $resolvedId = $this->resolveGroupedMatches($matches, $link, $context);

            if ($resolvedId === null) {
                continue;
            }

            $storageStrategy = $link->getStorageStrategy();
            $data = $storageStrategy->prepareData($data, $link, [$resolvedId]);

            $pending[] = [
                'link' => $link,
                'strategy' => $storageStrategy,
                'ids' => [$resolvedId],
            ];
        }

        return $pending;
    }

    /**
     * @param  Collection<int, RelationshipMatch>  $matches
     * @param  array<string, mixed>  $context
     */
    private function resolveGroupedMatches(
        Collection $matches,
        EntityLink $link,
        array $context,
    ): ?string {
        foreach ($matches as $match) {
            if ($match->isExisting() && $match->id !== null) {
                return $match->id;
            }
        }

        $creationName = $this->resolveCreationName($matches);

        if ($creationName === null) {
            return null;
        }

        $creationName = trim($creationName);

        if ($creationName === '') {
            return null;
        }

        $dedupKey = "{$link->key}:".mb_strtolower($creationName);

        if (isset($this->createdRecords[$dedupKey])) {
            return $this->createdRecords[$dedupKey];
        }

        if ($link->storageType === EntityLinkStorage::CustomFieldValue) {
            return $this->resolveRecordFieldByName($link, $creationName, $context, $dedupKey);
        }

        /** @var Model $record */
        $record = new $link->targetModelClass;
        $record->forceFill([
            'name' => $creationName,
            'team_id' => $context['team_id'],
            'creator_id' => $context['creator_id'],
            'creation_source' => CreationSource::IMPORT,
        ]);
        $record->save();

        $id = (string) $record->getKey();
        $this->createdRecords[$dedupKey] = $id;

        return $id;
    }

    /** @param  Collection<int, RelationshipMatch>  $matches */
    private function resolveCreationName(Collection $matches): ?string
    {
        $preferred = $matches->first(
            fn (RelationshipMatch $m): bool => $m->isCreate() && $m->behavior === MatchBehavior::Create
        );

        $preferred ??= $matches->first(
            fn (RelationshipMatch $m): bool => $m->isCreate() && $m->behavior === MatchBehavior::MatchOrCreate
        );

        if ($preferred === null || blank($preferred->name)) {
            return null;
        }

        return $preferred->name;
    }

    /**
     * Record custom fields should never auto-create target entities, only match existing ones.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveRecordFieldByName(
        EntityLink $link,
        string $name,
        array $context,
        string $dedupKey,
    ): ?string {
        $record = $link->targetModelClass::query()
            ->where('team_id', $context['team_id'])
            ->where('name', $name)
            ->first();

        if ($record === null) {
            return null;
        }

        $id = (string) $record->getKey();
        $this->createdRecords[$dedupKey] = $id;

        return $id;
    }

    /** @param  array<string, int>  $results */
    private function notifyUser(Import $import, array $results, bool $failed = false): void
    {
        $user = User::query()->find($import->user_id);

        if ($user === null) {
            return;
        }

        $entityLabel = $import->entity_type->label();
        $status = $failed ? 'failed' : 'completed';

        $notification = Notification::make()
            ->title("Import of {$entityLabel} {$status}")
            ->viewData(['results' => $results]);

        $failed
            ? $notification->danger()
            : $notification->success();

        $notification->sendToDatabase($user);
    }

    /**
     * @param  array<int, array{link: EntityLink, strategy: EntityLinkStorageInterface, ids: array<int|string>}>  $pendingRelationships
     * @param  array<string, mixed>  $context
     */
    private function storeEntityLinkRelationships(
        Model $record,
        array $pendingRelationships,
        array $context,
    ): void {
        foreach ($pendingRelationships as $pending) {
            $pending['strategy']->store($record, $pending['link'], $pending['ids'], $context);
        }
    }
}

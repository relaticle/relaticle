<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\User;
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
use Relaticle\CustomFields\Filament\Integration\Support\Imports\ImportDataStorage;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Support\SafeValueConverter;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ImportStatus;
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

    private const int MAX_STORED_ERRORS = 100;

    /** @var array<string, string> Dedup map for auto-created records: "{entityLinkKey}:{name}" => id */
    private array $createdRecords = [];

    /** @var list<array{row: int, error: string, data?: array<string, mixed>}> */
    private array $failedRows = [];

    /** @var list<int> */
    private array $processedRows = [];

    /** @var list<array<string, mixed>> */
    private array $pendingCustomFieldValues = [];

    public function __construct(
        private readonly string $importId,
        private readonly string $teamId,
    ) {}

    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);
        $store = ImportStore::load($this->importId);

        if ($store === null) {
            return;
        }

        $store->ensureProcessedColumn();

        $importer = $import->getImporter();
        $mappings = $import->columnMappings();

        $results = $import->results ?? ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $allowedKeys = $this->allowedAttributeKeys($importer);
        $customFieldDefs = $this->loadCustomFieldDefinitions($importer);
        $fieldMappings = $mappings->filter(fn (ColumnData $col): bool => $col->isFieldMapping());

        $context = [
            'team_id' => $this->teamId,
            'creator_id' => $import->user_id,
        ];

        try {
            $store->query()
                ->where('processed', false)
                ->orderBy('row_number')
                ->chunkById(500, function (Collection $rows) use ($importer, $fieldMappings, $allowedKeys, $customFieldDefs, $context, &$results, $store, $import): void {
                    $existingRecords = $this->preloadExistingRecords($rows, $importer);

                    foreach ($rows as $row) {
                        $this->processRow($row, $importer, $fieldMappings, $allowedKeys, $customFieldDefs, $context, $results, $existingRecords);
                    }

                    $this->flushProcessedRows($store);
                    $this->flushCustomFieldValues();
                    $this->persistResults($import, $results);
                });

            $import->update([
                'status' => ImportStatus::Completed,
                'completed_at' => now(),
                'results' => $results,
                'failed_rows_data' => $this->failedRowsSummary(),
                'created_rows' => $results['created'],
                'updated_rows' => $results['updated'],
                'skipped_rows' => $results['skipped'],
                'successful_rows' => $results['created'] + $results['updated'],
                'processed_rows' => array_sum($results),
            ]);

            $this->writeFailedRowsToDb($import);
            $this->notifyUser($import, $results);
        } catch (\Throwable $e) {
            $this->persistResults($import, $results);
            $import->update(['status' => ImportStatus::Failed]);

            try {
                $this->notifyUser($import, $results, failed: true);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    /**
     * @param  Collection<int, ColumnData>  $fieldMappings
     * @param  array<string, true>  $allowedKeys
     * @param  Collection<string, CustomField>  $customFieldDefs
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
        array $context,
        array &$results,
        array $existingRecords = [],
    ): void {
        if (! $row->isCreate() && ! $row->isUpdate()) {
            $results['skipped']++;
            $this->markProcessed($row);

            return;
        }

        try {
            $data = $this->buildDataFromRow($row, $fieldMappings);
            $existing = $row->isUpdate()
                ? ($existingRecords[(string) $row->matched_id] ?? $this->findExistingRecord($importer, $row->matched_id))
                : null;

            if ($row->isUpdate() && ! $existing instanceof Model) {
                $results['skipped']++;
                $this->markProcessed($row);

                return;
            }

            $isCreate = $row->isCreate();

            DB::transaction(function () use ($row, $importer, $data, $existing, $context, $isCreate, $allowedKeys, $customFieldDefs, &$results): void {
                $pendingRelationships = $this->resolveEntityLinkRelationships($row, $data, $importer, $context);

                $prepared = $importer->prepareForSave($data, $existing, $context);
                $customFieldData = $this->extractCustomFieldData($prepared);
                $prepared = array_intersect_key($prepared, $allowedKeys);

                $record = $isCreate
                    ? new ($importer->modelClass())
                    : $existing;

                if ($customFieldData !== []) {
                    ImportDataStorage::setMultiple($record, $customFieldData);
                }

                $record->forceFill($prepared);
                $record->save();

                $this->storeEntityLinkRelationships($record, $pendingRelationships, $context);

                $results[$isCreate ? 'created' : 'updated']++;

                $storedCustomFieldData = ImportDataStorage::pull($record);
                $batchableData = array_intersect_key($storedCustomFieldData, $customFieldDefs->all());
                $remainingData = array_diff_key($storedCustomFieldData, $batchableData);

                $this->collectCustomFieldValues($record, $batchableData, $customFieldDefs);

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

    /** @return Collection<string, CustomField> keyed by code */
    private function loadCustomFieldDefinitions(BaseImporter $importer): Collection
    {
        return CustomField::query()
            ->withoutGlobalScopes()
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
     */
    private function collectCustomFieldValues(
        Model $record,
        array $customFieldData,
        Collection $customFieldDefs,
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

            $valueColumn = CustomFieldValue::getValueColumn($cf->type);
            $safeValue = SafeValueConverter::toDbSafe($value, $cf->type);

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
            ->whereIn((new $modelClass)->getKeyName(), $updateIds)
            ->get()
            ->keyBy(fn (Model $model): string => (string) $model->getKey())
            ->all();
    }

    /** @param array<string, mixed> $rawData */
    private function recordFailedRow(int $rowNumber, array $rawData, \Throwable $e): void
    {
        if (count($this->failedRows) >= self::MAX_STORED_ERRORS) {
            return;
        }

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
            'results' => $results,
            'failed_rows_data' => $this->failedRowsSummary(),
        ]);
    }

    /** @return list<array{row: int, error: string}> */
    private function failedRowsSummary(): array
    {
        return array_map(
            fn (array $row): array => ['row' => $row['row'], 'error' => $row['error']],
            $this->failedRows,
        );
    }

    private function writeFailedRowsToDb(Import $import): void
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
            ->find($matchedId);
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

        foreach ($row->relationships as $match) {
            $link = $entityLinks[$match->relationship] ?? null;

            if ($link === null) {
                continue;
            }

            $resolvedId = $this->resolveMatchId($match, $link, $context);

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

    /** @param  array<string, mixed>  $context */
    private function resolveMatchId(RelationshipMatch $match, EntityLink $link, array $context): ?string
    {
        if ($match->isExisting() && $match->id !== null) {
            return $match->id;
        }

        if (! $match->isCreate()) {
            return null;
        }

        if (! $link->canCreate || blank($match->name)) {
            return null;
        }

        $dedupKey = "{$link->key}:".mb_strtolower(trim($match->name));

        if (isset($this->createdRecords[$dedupKey])) {
            return $this->createdRecords[$dedupKey];
        }

        /** @var Model $record */
        $record = new $link->targetModelClass;
        $record->forceFill([
            'name' => $match->name,
            'team_id' => $context['team_id'],
            'creator_id' => $context['creator_id'],
            'creation_source' => CreationSource::IMPORT,
        ]);
        $record->save();

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

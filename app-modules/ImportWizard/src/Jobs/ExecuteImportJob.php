<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use App\Enums\CreationSource;
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
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;
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

    /** @var list<array{row: int, error: string}> */
    private array $failedRows = [];

    public function __construct(
        private readonly string $importId,
        private readonly string $teamId,
    ) {}

    public function handle(): void
    {
        $store = ImportStore::load($this->importId, $this->teamId);

        if ($store === null) {
            return;
        }

        $store->ensureProcessedColumn();

        $importer = $store->getImporter();
        $mappings = $store->columnMappings();

        $results = $store->results() ?? ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $store->query()
                ->where('processed', false)
                ->orderBy('row_number')
                ->chunkById(100, function ($rows) use ($importer, $mappings, &$results, $store): void {
                    foreach ($rows as $row) {
                        $this->processRow($row, $importer, $mappings, $results, $store);
                    }

                    $this->persistResults($store, $results);
                });

            $store->setStatus(ImportStatus::Completed);
            $this->notifyUser($store, $results);
        } catch (\Throwable $e) {
            $this->persistResults($store, $results);
            $store->setStatus(ImportStatus::Failed);

            try {
                $this->notifyUser($store, $results, failed: true);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    /**
     * @param  Collection<int, ColumnData>  $mappings
     * @param  array<string, int>  $results
     */
    private function processRow(
        ImportRow $row,
        BaseImporter $importer,
        Collection $mappings,
        array &$results,
        ImportStore $store,
    ): void {
        if (! $row->isCreate() && ! $row->isUpdate()) {
            $results['skipped']++;
            $this->markProcessed($row);

            return;
        }

        try {
            $data = $this->buildDataFromRow($row, $mappings);
            $existing = $row->isUpdate() ? $this->findExistingRecord($importer, $row->matched_id) : null;

            if ($row->isUpdate() && $existing === null) {
                $results['skipped']++;
                $this->markProcessed($row);

                return;
            }

            $context = [
                'team_id' => $this->teamId,
                'creator_id' => $store->userId(),
            ];

            $isCreate = $row->isCreate();

            DB::transaction(function () use ($row, $importer, $data, $existing, $context, $isCreate, &$results): void {
                $pendingRelationships = $this->resolveEntityLinkRelationships($row, $data, $importer, $context);

                $prepared = $importer->prepareForSave($data, $existing, $context);
                $customFieldData = $this->extractCustomFieldData($prepared);

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

                $importer->afterSave($record, $context);
            });

            $this->markProcessed($row);
        } catch (\Throwable $e) {
            $results['failed']++;
            $this->recordFailedRow($row->row_number, $e);
            report($e);
        }
    }

    private function markProcessed(ImportRow $row): void
    {
        $row->updateQuietly(['processed' => true]);
    }

    private function recordFailedRow(int $rowNumber, \Throwable $e): void
    {
        if (count($this->failedRows) >= self::MAX_STORED_ERRORS) {
            return;
        }

        $this->failedRows[] = [
            'row' => $rowNumber,
            'error' => Str::limit($e->getMessage(), 500),
        ];
    }

    /** @param  array<string, int>  $results */
    private function persistResults(ImportStore $store, array $results): void
    {
        $store->setResults($results);

        if ($this->failedRows !== []) {
            $store->updateMeta(['failed_rows' => $this->failedRows]);
        }
    }

    /**
     * @param  Collection<int, ColumnData>  $mappings
     * @return array<string, mixed>
     */
    private function buildDataFromRow(ImportRow $row, Collection $mappings): array
    {
        return $mappings
            ->filter(fn (ColumnData $mapping): bool => $mapping->isFieldMapping())
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
    private function notifyUser(ImportStore $store, array $results, bool $failed = false): void
    {
        $user = User::find($store->userId());

        if ($user === null) {
            return;
        }

        $entityLabel = $store->entityType()->label();
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

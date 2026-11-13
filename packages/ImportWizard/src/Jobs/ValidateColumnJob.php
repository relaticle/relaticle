<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use App\Support\CurrentWorkspace;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;
use Throwable;

#[Timeout(self::TIMEOUT_SECONDS)]
#[Tries(1)]
final class ValidateColumnJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    private const int TIMEOUT_SECONDS = 120;

    private const int LOCK_WAIT_MARGIN_SECONDS = 15;

    private int $startedAt = 0;

    public function __construct(
        private readonly string $importId,
        private readonly ColumnData $column,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $this->startedAt = hrtime(true);
        $import = Import::query()->findOrFail($this->importId);

        try {
            $reader = ImportStore::forRead($this->importId);
        } catch (Throwable $e) {
            throw_if(ImportStore::exists($this->importId), $e);

            return;
        }

        if (! $reader instanceof ImportStore) {
            return;
        }

        $jsonPath = '$.'.$this->column->source;

        try {
            resolve(CurrentWorkspace::class)->within($import->workspace_id, function () use ($import, $reader, $jsonPath): void {
                $this->column->isEntityLinkMapping()
                    ? $this->validateEntityLink($import, $reader, $jsonPath)
                    : $this->validateField($import, $reader, $jsonPath);
            });
        } catch (ImportStoreException $e) {
            throw_unless($e->isNotFound(), $e);
        } finally {
            $reader->close();
        }
    }

    private function validateField(Import $import, ImportStore $reader, string $jsonPath): void
    {
        $uniqueValues = $this->fetchUncorrectedUniqueValues($reader, $jsonPath);
        $results = $uniqueValues === [] ? [] : $this->validateValues($import, $uniqueValues);

        if ($results === [] && ! $this->column->getType()->isDateOrDateTime()) {
            return;
        }

        ImportStore::withWriteLock($this->importId, function (ImportStore $store) use ($jsonPath, $results): void {
            if ($this->batch()?->cancelled()) {
                return;
            }

            $this->clearValidationForCorrectedDateFields($store->connection(), $jsonPath);

            if ($results !== []) {
                $this->updateValidationErrors($store->connection(), $jsonPath, $results);
            }
        }, $this->lockWaitSeconds());
    }

    private function validateEntityLink(Import $import, ImportStore $reader, string $jsonPath): void
    {
        $uniqueValues = $reader->query()
            ->selectRaw('DISTINCT COALESCE(json_extract(corrections, ?), json_extract(raw_data, ?)) as value', [$jsonPath, $jsonPath])
            ->pluck('value')
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();

        if ($uniqueValues === []) {
            return;
        }

        $validator = new EntityLinkValidator($import->workspace_id);
        $errorMap = $validator->batchValidateFromColumn($this->column, $import->getImporter(), $uniqueValues);

        $results = [];
        foreach ($errorMap as $value => $error) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $error,
            ];
        }

        $context = $this->column->resolveEntityLinkContext($import->getImporter());
        $inserts = $context === null
            ? []
            : $this->relationshipInserts($context, $validator, $uniqueValues, $validator->getLastFormatErrors());

        ImportStore::withWriteLock($this->importId, function (ImportStore $store) use ($jsonPath, $results, $context, $uniqueValues, $inserts): void {
            if ($this->batch()?->cancelled()) {
                return;
            }

            $this->updateValidationErrors($store->connection(), $jsonPath, $results);

            if ($context !== null) {
                $this->applyRelationships($store->connection(), $jsonPath, $context['link']->key, $context['matcher']->field, $uniqueValues, $inserts);
            }
        }, $this->lockWaitSeconds());
    }

    private function lockWaitSeconds(): int
    {
        $elapsed = (int) ((hrtime(true) - $this->startedAt) / 1e9);

        return max(1, min(
            (int) config('import-wizard.store.lock.wait.job'),
            self::TIMEOUT_SECONDS - $elapsed - self::LOCK_WAIT_MARGIN_SECONDS,
        ));
    }

    /**
     * @param  array{link: EntityLink, matcher: MatchableField}  $context
     * @param  array<int, string>  $uniqueValues
     * @param  array<string, string|null>  $errorMap
     * @return array<string, string>
     */
    private function relationshipInserts(
        array $context,
        EntityLinkValidator $validator,
        array $uniqueValues,
        array $errorMap,
    ): array {
        $link = $context['link'];
        $matcher = $context['matcher'];

        $validValues = array_filter($uniqueValues, fn (string $v): bool => ($errorMap[$v] ?? null) === null);

        $resolvedMap = $matcher->behavior === MatchBehavior::Create
            ? array_fill_keys($validValues, null)
            : $validator->getResolver()->batchResolve($link, $matcher, $validValues);

        $inserts = [];

        foreach ($resolvedMap as $value => $resolvedId) {
            if (blank($value) || ($resolvedId === null && $matcher->behavior === MatchBehavior::MatchOnly)) {
                continue;
            }

            $match = $resolvedId !== null
                ? RelationshipMatch::existing($link->key, (string) $resolvedId, $matcher->behavior, $matcher->field)
                : RelationshipMatch::create($link->key, (string) $value, $matcher->behavior, $matcher->field);

            $inserts[(string) $value] = json_encode($match->toArray(), JSON_THROW_ON_ERROR);
        }

        return $inserts;
    }

    /**
     * @param  array<int, string>  $uniqueValues
     * @param  array<string, string>  $inserts
     */
    private function applyRelationships(
        Connection $connection,
        string $jsonPath,
        string $linkKey,
        string $matcherField,
        array $uniqueValues,
        array $inserts,
    ): void {
        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_relationships (
                lookup_value TEXT,
                relationship_json TEXT
            )
        ');

        try {
            $connection->table('temp_relationships')->insert(array_map(fn (string $value): array => [
                'lookup_value' => $value,
                'relationship_json' => $inserts[$value] ?? null,
            ], $uniqueValues));

            $connection->statement("
                UPDATE import_rows
                SET relationships = NULLIF((
                    SELECT json_group_array(json(value))
                    FROM (
                        SELECT value FROM json_each(import_rows.relationships)
                        WHERE json_extract(value, '\$.relationship') != ?
                           OR json_extract(value, '\$.matchField') IS NOT ?
                        UNION ALL
                        SELECT temp.relationship_json
                        WHERE temp.relationship_json IS NOT NULL
                          AND COALESCE(json_extract(import_rows.skipped, ?), 0) = 0
                    )
                ), '[]')
                FROM temp_relationships AS temp
                WHERE COALESCE(json_extract(import_rows.corrections, ?), json_extract(import_rows.raw_data, ?)) = temp.lookup_value
            ", [$linkKey, $matcherField, $jsonPath, $jsonPath, $jsonPath]);
        } finally {
            $connection->statement('DROP TABLE IF EXISTS temp_relationships');
        }
    }

    private function clearValidationForCorrectedDateFields(
        Connection $connection,
        string $jsonPath,
    ): void {
        if (! $this->column->getType()->isDateOrDateTime()) {
            return;
        }

        $connection->statement("
            UPDATE import_rows
            SET validation = json_remove(COALESCE(validation, '{}'), ?)
            WHERE json_extract(corrections, ?) IS NOT NULL
        ", [$jsonPath, $jsonPath]);
    }

    /** @return array<int, string> */
    private function fetchUncorrectedUniqueValues(ImportStore $store, string $jsonPath): array
    {
        return $store->query()
            ->selectRaw('DISTINCT json_extract(raw_data, ?) as value', [$jsonPath])
            ->whereRaw('json_extract(corrections, ?) IS NULL', [$jsonPath])
            ->pluck('value')
            ->filter()
            ->all();
    }

    /**
     * @param  array<int, string>  $uniqueValues
     * @return array<int, array{raw_value: string, validation_error: string|null}>
     */
    private function validateValues(Import $import, array $uniqueValues): array
    {
        $this->hydrateColumnField($import);

        $validator = new ColumnValidator;
        $results = [];

        foreach ($uniqueValues as $value) {
            $error = $validator->validate($this->column, $value);
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $error?->toStorageFormat(),
            ];
        }

        return $results;
    }

    private function hydrateColumnField(Import $import): void
    {
        if ($this->column->importField instanceof ImportField) {
            return;
        }

        $importer = $import->getImporter();
        $this->column->importField = $importer->allFields()->getByKey($this->column->target);
    }

    /**
     * @param  array<int, array{raw_value: string, validation_error: string|null}>  $results
     *
     * @throws Throwable
     */
    private function updateValidationErrors(
        Connection $connection,
        string $jsonPath,
        array $results,
    ): void {
        $connection->transaction(function () use ($connection, $jsonPath, $results): void {
            $connection->statement('
                CREATE TEMPORARY TABLE IF NOT EXISTS temp_validation (
                    raw_value TEXT,
                    validation_error TEXT
                )
            ');

            try {
                $connection->table('temp_validation')->insert($results);

                $valueCondition = $this->column->isEntityLinkMapping()
                    ? 'COALESCE(json_extract(import_rows.corrections, ?), json_extract(import_rows.raw_data, ?)) = temp.raw_value'
                    : 'json_extract(import_rows.raw_data, ?) = temp.raw_value AND json_extract(import_rows.corrections, ?) IS NULL';

                $connection->statement("
                    UPDATE import_rows
                    SET validation = CASE
                        WHEN temp.validation_error IS NULL
                            THEN json_remove(COALESCE(validation, '{}'), ?)
                        ELSE
                            json_set(COALESCE(validation, '{}'), ?, temp.validation_error)
                    END
                    FROM temp_validation AS temp
                    WHERE {$valueCondition}
                ", [$jsonPath, $jsonPath, $jsonPath, $jsonPath]);
            } finally {
                $connection->statement('DROP TABLE IF EXISTS temp_validation');
            }
        });
    }
}

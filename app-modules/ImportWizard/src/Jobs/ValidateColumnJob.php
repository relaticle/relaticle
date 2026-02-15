<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;

final class ValidateColumnJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        private readonly string $importId,
        private readonly ColumnData $column,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = Import::query()->findOrFail($this->importId);
        $store = ImportStore::load($this->importId);

        if (! $store instanceof ImportStore) {
            return;
        }

        $connection = $store->connection();
        $jsonPath = '$.'.$this->column->source;

        if ($this->column->isEntityLinkMapping()) {
            $this->validateEntityLink($import, $store, $connection, $jsonPath);

            return;
        }

        $this->clearValidationForCorrectedDateFields($connection, $jsonPath);

        $uniqueValues = $this->fetchUncorrectedUniqueValues($store, $jsonPath);

        if ($uniqueValues === []) {
            return;
        }

        $results = $this->validateValues($import, $uniqueValues);

        $this->updateValidationErrors($connection, $jsonPath, $results);
    }

    private function validateEntityLink(Import $import, ImportStore $store, Connection $connection, string $jsonPath): void
    {
        $uniqueValues = $this->fetchUncorrectedUniqueValues($store, $jsonPath);

        if ($uniqueValues === []) {
            return;
        }

        $validator = new EntityLinkValidator($import->team_id);
        $errorMap = $validator->batchValidateFromColumn($this->column, $import->getImporter(), $uniqueValues);

        $results = [];
        foreach ($errorMap as $value => $error) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $error,
            ];
        }

        $this->updateValidationErrors($connection, $jsonPath, $results);
        $this->writeEntityLinkRelationships($import, $connection, $jsonPath, $validator, $uniqueValues, $validator->getLastFormatErrors());
    }

    /**
     * @param  array<int, string>  $uniqueValues
     * @param  array<string, string|null>  $errorMap
     */
    private function writeEntityLinkRelationships(
        Import $import,
        Connection $connection,
        string $jsonPath,
        EntityLinkValidator $validator,
        array $uniqueValues,
        array $errorMap = [],
    ): void {
        $context = $this->column->resolveEntityLinkContext($import->getImporter());

        if ($context === null) {
            return;
        }

        $link = $context['link'];
        $matcher = $context['matcher'];

        $validValues = array_filter($uniqueValues, fn (string $v): bool => ($errorMap[$v] ?? null) === null);

        $resolvedMap = $matcher->behavior === MatchBehavior::Create
            ? array_fill_keys($validValues, null)
            : $validator->getResolver()->batchResolve($link, $matcher, $validValues);

        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_relationships (
                lookup_value TEXT,
                relationship_json TEXT
            )
        ');

        try {
            $inserts = [];

            foreach ($resolvedMap as $value => $resolvedId) {
                if ($resolvedId === null && $matcher->behavior === MatchBehavior::MatchOnly) {
                    continue;
                }

                $match = $resolvedId !== null
                    ? RelationshipMatch::existing($link->key, (string) $resolvedId, $matcher->behavior, $matcher->field)
                    : RelationshipMatch::create($link->key, (string) $value, $matcher->behavior, $matcher->field);

                $inserts[] = [
                    'lookup_value' => (string) $value,
                    'relationship_json' => json_encode($match->toArray()),
                ];
            }

            if ($inserts === []) {
                return;
            }

            $connection->table('temp_relationships')->insert($inserts);

            $connection->statement("
                UPDATE import_rows
                SET relationships = json_insert(
                    COALESCE(relationships, '[]'),
                    '\$[#]',
                    json(temp.relationship_json)
                )
                FROM temp_relationships AS temp
                WHERE json_extract(import_rows.raw_data, ?) = temp.lookup_value
            ", [$jsonPath]);
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
        if ($this->column->importField instanceof \Relaticle\ImportWizard\Data\ImportField) {
            return;
        }

        $importer = $import->getImporter();
        $this->column->importField = $importer->allFields()->get($this->column->target);
    }

    /**
     * @param  array<int, array{raw_value: string, validation_error: string|null}>  $results
     *
     * @throws \Throwable
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

                $connection->statement("
                    UPDATE import_rows
                    SET validation = CASE
                        WHEN temp.validation_error IS NULL
                            THEN json_remove(COALESCE(validation, '{}'), ?)
                        ELSE
                            json_set(COALESCE(validation, '{}'), ?, temp.validation_error)
                    END
                    FROM temp_validation AS temp
                    WHERE json_extract(import_rows.raw_data, ?) = temp.raw_value
                      AND json_extract(import_rows.corrections, ?) IS NULL
                ", [$jsonPath, $jsonPath, $jsonPath, $jsonPath]);
            } finally {
                $connection->statement('DROP TABLE IF EXISTS temp_validation');
            }
        });
    }
}

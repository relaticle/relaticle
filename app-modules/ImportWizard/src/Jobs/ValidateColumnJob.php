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
        private readonly ?string $teamId = null,
    ) {}

    public function handle(): void
    {
        $store = ImportStore::load($this->importId, $this->teamId);

        if ($store === null) {
            return;
        }

        $connection = $store->connection();
        $jsonPath = '$.'.$this->column->source;

        if ($this->column->isEntityLinkMapping()) {
            $this->validateEntityLink($store, $connection, $jsonPath);

            return;
        }

        $this->clearValidationForCorrectedDateFields($connection, $jsonPath);

        $uniqueValues = $this->fetchUncorrectedUniqueValues($store, $jsonPath);

        if (empty($uniqueValues)) {
            return;
        }

        $results = $this->validateValues($store, $uniqueValues);

        $this->updateValidationErrors($connection, $jsonPath, $results);
    }

    private function validateEntityLink(ImportStore $store, Connection $connection, string $jsonPath): void
    {
        $uniqueValues = $this->fetchUncorrectedUniqueValues($store, $jsonPath);

        if (empty($uniqueValues)) {
            return;
        }

        $validator = new EntityLinkValidator($store->teamId());
        $errorMap = $validator->batchValidateFromColumn($this->column, $store->getImporter(), $uniqueValues);

        $results = [];
        foreach ($errorMap as $value => $error) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $error,
            ];
        }

        $this->updateValidationErrors($connection, $jsonPath, $results);
        $this->writeEntityLinkRelationships($store, $connection, $jsonPath, $validator, $uniqueValues);
    }

    /** @param array<int, string> $uniqueValues */
    private function writeEntityLinkRelationships(
        ImportStore $store,
        Connection $connection,
        string $jsonPath,
        EntityLinkValidator $validator,
        array $uniqueValues,
    ): void {
        $context = $this->column->resolveEntityLinkContext($store->getImporter());

        if ($context === null) {
            return;
        }

        $link = $context['link'];
        $matcher = $context['matcher'];

        $resolvedMap = $matcher->behavior === MatchBehavior::AlwaysCreate
            ? array_fill_keys($uniqueValues, null)
            : $validator->getResolver()->batchResolve($link, $matcher, $uniqueValues);

        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_relationships (
                lookup_value TEXT,
                relationship_json TEXT
            )
        ');

        try {
            $inserts = [];

            foreach ($resolvedMap as $value => $resolvedId) {
                if ($resolvedId === null && $matcher->behavior === MatchBehavior::UpdateOnly) {
                    continue;
                }

                $match = $resolvedId !== null
                    ? RelationshipMatch::existing($link->key, (string) $resolvedId)
                    : RelationshipMatch::create($link->key, (string) $value);

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

    /**
     * @return array<int, string>
     */
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
    private function validateValues(ImportStore $store, array $uniqueValues): array
    {
        $this->hydrateColumnField($store);

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

    private function hydrateColumnField(ImportStore $store): void
    {
        if ($this->column->importField !== null) {
            return;
        }

        $importer = $store->getImporter();
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

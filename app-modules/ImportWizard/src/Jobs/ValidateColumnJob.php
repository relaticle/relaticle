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
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ImportValueValidator;

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
        $store = ImportStore::load($this->importId);

        if ($store === null) {
            return;
        }

        $connection = $store->connection();
        $jsonPath = '$.'.$this->column->source;

        $this->clearValidationForCorrectedDateFields($connection, $jsonPath);

        $uniqueValues = $this->fetchUncorrectedUniqueValues($store, $jsonPath);

        if (empty($uniqueValues)) {
            return;
        }

        $results = $this->validateValues($store, $uniqueValues);

        $this->updateValidationErrors($connection, $jsonPath, $results);
    }

    /**
     * Date corrections are stored in ISO format (from HTML date picker).
     * ISO is always valid regardless of the selected display format.
     */
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
        $validator = new ImportValueValidator($store->entityType()->value);
        $results = [];

        foreach ($uniqueValues as $value) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $validator->validate($this->column, $value),
            ];
        }

        return $results;
    }

    /**
     * @param array<int, array{raw_value: string, validation_error: string|null}> $results
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

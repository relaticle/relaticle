<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        // Fetch ALL unique values for this column (single query)
        $uniqueValues = $store->query()
            ->selectRaw('DISTINCT json_extract(raw_data, ?) as value', [$jsonPath])
            ->pluck('value')
            ->filter()
            ->all();

        if (empty($uniqueValues)) {
            return;
        }

        // Validate all values in memory (fast - ~0.1ms per value)
        $validator = new ImportValueValidator($store->entityType()->value);
        $results = [];

        foreach ($uniqueValues as $value) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $validator->validate($this->column, $value),
            ];
        }

        // Create temp table
        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_validation (
                raw_value TEXT,
                validation_error TEXT
            )
        ');

        // Batch insert into temp table
        $connection->table('temp_validation')->insert($results);

        // Single UPDATE from temp table (replaces N individual UPDATEs)
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
        ", [$jsonPath, $jsonPath, $jsonPath]);

        // Cleanup temp table
        $connection->statement('DROP TABLE IF EXISTS temp_validation');
    }
}

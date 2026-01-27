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

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $uniqueValues
     */
    public function __construct(
        private readonly string $importId,
        private readonly ColumnData $column,
        private readonly array $uniqueValues,
    ) {}

    public function handle(): void
    {
        $store = ImportStore::load($this->importId);

        if ($store === null) {
            return;
        }

        $validator = new ImportValueValidator($store->entityType()->value);
        $connection = $store->connection();
        $jsonPath = '$.'.$this->column->source;

        // Step 1: Validate all values in memory (fast - ~0.1ms per value)
        $results = [];
        foreach ($this->uniqueValues as $value) {
            $results[] = [
                'raw_value' => $value,
                'validation_error' => $validator->validate($this->column, $value),
            ];
        }

        // Step 2: Create temp table (IF NOT EXISTS for concurrent job safety)
        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_validation (
                raw_value TEXT,
                validation_error TEXT
            )
        ');

        // Step 3: Batch insert into temp table
        $connection->table('temp_validation')->insert($results);

        // Step 4: Single UPDATE from temp table (replaces 500+ individual UPDATEs)
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

        // Step 5: Cleanup temp table for next chunk
        $connection->statement('DELETE FROM temp_validation');
    }
}

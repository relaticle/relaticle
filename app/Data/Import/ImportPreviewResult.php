<?php

declare(strict_types=1);

namespace App\Data\Import;

use Spatie\LaravelData\Data;

/**
 * Result of a dry-run import preview showing what will happen when import is executed.
 */
final class ImportPreviewResult extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  All rows with mapped field values and _is_new flag
     */
    public function __construct(
        public int $totalRows,
        public int $createCount,
        public int $updateCount,
        public array $rows = [],
    ) {}

    public function summary(): string
    {
        $parts = [];

        if ($this->createCount > 0) {
            $parts[] = "{$this->createCount} will be created";
        }

        if ($this->updateCount > 0) {
            $parts[] = "{$this->updateCount} will be updated";
        }

        return implode(', ', $parts);
    }
}

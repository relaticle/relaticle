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
     * @param  array<int, array<string, mixed>>  $sampleCreates  First N records that will be created
     * @param  array<int, array<string, mixed>>  $sampleUpdates  First N records that will be updated
     * @param  array<int, array{row: int, message: string}>  $errors  Validation errors by row
     */
    public function __construct(
        public int $totalRows,
        public int $createCount,
        public int $updateCount,
        public int $skipCount,
        public int $errorCount,
        public array $sampleCreates = [],
        public array $sampleUpdates = [],
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    public function successRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return (($this->createCount + $this->updateCount) / $this->totalRows) * 100;
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->createCount > 0) {
            $parts[] = "{$this->createCount} will be created";
        }

        if ($this->updateCount > 0) {
            $parts[] = "{$this->updateCount} will be updated";
        }

        if ($this->skipCount > 0) {
            $parts[] = "{$this->skipCount} will be skipped";
        }

        if ($this->errorCount > 0) {
            $parts[] = "{$this->errorCount} will fail";
        }

        return implode(', ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Spatie\LaravelData\Data;

/**
 * Result of a dry-run import preview showing what will happen when import is executed.
 */
final class ImportPreviewResult extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  All rows with mapped field values and _is_new flag
     * @param  bool  $isSampled  Whether the preview is based on a sample of rows
     * @param  int  $sampleSize  Number of rows actually processed for the preview
     */
    public function __construct(
        public readonly int $totalRows,
        public readonly int $createCount,
        public readonly int $updateCount,
        public readonly array $rows = [],
        public readonly bool $isSampled = false,
        public readonly int $sampleSize = 0,
    ) {}
}

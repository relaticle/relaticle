<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Relaticle\ImportWizard\Models\Import;

/**
 * Event fired when an import chunk completes processing.
 *
 * Used for real-time progress tracking and monitoring.
 */
final class ImportChunkProcessed
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Import $import,
        public readonly int $processedRows,
        public readonly int $successfulRows,
        public readonly int $failedRows,
    ) {}
}

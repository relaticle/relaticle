<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use Illuminate\Support\Str;

/**
 * Holds a single `batch_uuid` for the lifetime of one request or queued job, so
 * every activity row written while handling it shares the same value. This is the
 * key the timeline groups on when collapsing a single save's native + custom-field
 * rows into one entry.
 *
 * Bound as a scoped container instance — Laravel forgets scoped instances between
 * HTTP requests and between queue jobs, so the uuid never leaks across them.
 */
final class RequestActivityBatch
{
    private ?string $uuid = null;

    public function id(): string
    {
        return $this->uuid ??= (string) Str::uuid();
    }
}

<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

/**
 * The import a queued job is running, so every activity row it writes can be tied
 * back to it. Bound as a scoped instance, and cleared by the job when it ends,
 * because a sync queue runs the job inside a request that keeps writing afterwards.
 */
final class CurrentImport
{
    private ?string $id = null;

    private ?string $fileName = null;

    public function set(string $id, string $fileName): void
    {
        $this->id = $id;
        $this->fileName = $fileName;
    }

    public function clear(): void
    {
        $this->id = null;
        $this->fileName = null;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function fileName(): ?string
    {
        return $this->fileName;
    }
}

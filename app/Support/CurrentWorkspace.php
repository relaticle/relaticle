<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Workspace;

final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

trait HasExplicitToolAnnotations
{
    /**
     * @return array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => $this->readOnlyHint(),
            'destructiveHint' => $this->destructiveHint(),
            'idempotentHint' => $this->idempotentHint(),
            'openWorldHint' => $this->openWorldHint(),
        ];
    }

    protected function readOnlyHint(): bool
    {
        return false;
    }

    protected function destructiveHint(): bool
    {
        return true;
    }

    protected function idempotentHint(): bool
    {
        return false;
    }

    protected function openWorldHint(): bool
    {
        return false;
    }
}

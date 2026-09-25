<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

trait HasReadOnlyToolAnnotations
{
    use HasExplicitToolAnnotations;

    protected function readOnlyHint(): bool
    {
        return true;
    }

    protected function destructiveHint(): bool
    {
        return false;
    }

    protected function idempotentHint(): bool
    {
        return true;
    }
}

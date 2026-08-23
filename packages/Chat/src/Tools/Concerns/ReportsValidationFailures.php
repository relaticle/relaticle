<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait ReportsValidationFailures
{
    /**
     * Render a validation failure as the tools' `{"error": "..."}` contract.
     *
     * Every message is returned, not just the first, so the assistant can correct
     * the whole payload in one turn instead of rediscovering faults one at a time.
     */
    protected function validationError(ValidationException $exception): string
    {
        return (string) json_encode([
            'error' => implode(' ', Arr::flatten($exception->errors())),
        ], JSON_UNESCAPED_SLASHES);
    }
}

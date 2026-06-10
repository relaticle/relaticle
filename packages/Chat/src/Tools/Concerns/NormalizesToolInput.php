<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use Laravel\Ai\Tools\Request;

trait NormalizesToolInput
{
    /**
     * Drop only genuinely-absent (null) entries, preserving falsy-but-valid
     * values like "0", 0, false, "". Use instead of bare array_filter().
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function dropNull(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Coerce a tool-provided value into a clean list of non-empty string ids.
     * A lone scalar is wrapped into a single-element list (LLMs sometimes emit
     * a scalar where an array is declared). null/unusable input yields [].
     *
     * @return list<string>
     */
    protected function coerceIdList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $candidates = is_array($value) ? $value : [$value];

        $clean = [];
        foreach ($candidates as $id) {
            if (is_string($id) && $id !== '') {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    /**
     * Returns the coerced id list when the field is present, or null when it is
     * absent (meaning "no change"). Present-but-scalar coerces instead of dropping.
     *
     * @return list<string>|null
     */
    protected function idListOrNull(Request $request, string $key): ?array
    {
        if (! array_key_exists($key, $request->all())) {
            return null;
        }

        return $this->coerceIdList($request[$key]);
    }
}

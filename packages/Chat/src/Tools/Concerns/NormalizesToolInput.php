<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use Laravel\Ai\Tools\Request;

trait NormalizesToolInput
{
    protected function withoutNullArguments(Request $request): Request
    {
        return new Request(
            array_filter($request->all(), static fn (mixed $argument): bool => $argument !== null),
            $request->toolCallId(),
            $request->toolInvocationId(),
        );
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
            if (! is_scalar($id)) {
                continue;
            }

            $trimmed = trim((string) $id);

            if ($trimmed !== '') {
                $clean[] = $trimmed;
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

    /**
     * Coerce a record field into a list of ids, or null when it carries nothing
     * usable. Unlike idListOrNull() an empty list collapses to null: the create
     * path treats "no ids" as "omit the field" rather than "clear the relation".
     *
     * @param  array<string, mixed>  $record
     * @return list<string>|null
     */
    protected function idListFromArray(array $record, string $key): ?array
    {
        $ids = $this->coerceIdList($record[$key] ?? null);

        return $ids === [] ? null : $ids;
    }
}

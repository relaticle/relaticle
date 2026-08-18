<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use stdClass;

/**
 * Rewrites the UTC datetimes in a serialised tool payload into the signed-in user's
 * zone, carrying the offset.
 *
 * The agent prompt already tells the model which zone the user thinks in, but the
 * payload used to carry bare `...Z` timestamps, so answering "is this due today?" meant
 * the model doing timezone arithmetic. In a live chat it got that wrong. Emitting
 * `2026-08-19T08:30:00+09:00` moves the conversion to where it is deterministic, and the
 * offset means a model that ignores it still cannot be actively misled.
 *
 * This lives in the chat layer on purpose. The payloads come from `App\Http\Resources\V1`,
 * which the public REST API and the MCP server also serve, and ISO-8601 UTC is the right
 * contract for those — converting inside the resource would be a breaking API change.
 */
trait LocalisesDatetimes
{
    /**
     * Walks the payload rather than taking a list of known date keys: custom fields are
     * per-tenant, so the datetime keys are not knowable ahead of time.
     *
     * Three shapes have to be handled. The list tools round-trip through json_encode
     * first, so their datetimes arrive as ISO-8601 strings. The show tool passes the
     * resolved resource straight through, where they are still Carbon instances nested
     * inside the stdClass that JsonApiResource wraps `attributes` in — so the walk has
     * to descend into objects as well as arrays.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function localiseDatetimes(array $payload, ?User $user): array
    {
        $timezone = $user?->effectiveTimezone() ?? (string) config('app.timezone');

        return $this->localiseValue($payload, $timezone);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function localiseValue(array $values, string $timezone): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->localiseValue($value, $timezone);

                continue;
            }

            if ($value instanceof CarbonInterface) {
                $values[$key] = $value->copy()->setTimezone($timezone)->toIso8601String();

                continue;
            }

            if ($value instanceof stdClass) {
                $values[$key] = (object) $this->localiseValue(get_object_vars($value), $timezone);

                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->localiseString($value, $timezone);
            }
        }

        return $values;
    }

    /**
     * Only an unambiguous UTC instant is rewritten. A date-only value ("2026-08-19") is
     * deliberately left alone: it has no time of day, so shifting it would move the
     * calendar day for every user west of UTC.
     */
    private function localiseString(string $value, string $timezone): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return $value;
        }

        $parsed = rescue(
            fn (): CarbonInterface => Date::parse($value)->setTimezone($timezone),
            report: false,
        );

        return $parsed instanceof CarbonInterface
            ? $parsed->toIso8601String()
            : $value;
    }
}

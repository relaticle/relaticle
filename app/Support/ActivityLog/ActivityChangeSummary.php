<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Models\ActivityLog\Activity;
use Illuminate\Support\Str;

/**
 * Turns one activity row into the "old → new" lines an admin reads in the
 * workspace audit log.
 *
 * Only genuine before/after pairs are emitted. A create or a delete carries the
 * whole record in its payload, which says nothing the event badge does not
 * already say, so those produce no lines at all.
 */
final readonly class ActivityChangeSummary
{
    private const string ARROW = ' → ';

    private const string EMPTY = '—';

    /**
     * @return list<string>
     */
    public static function for(Activity $activity): array
    {
        return [
            ...self::nativeLines($activity),
            ...self::customFieldLines($activity),
        ];
    }

    /**
     * @return list<string>
     */
    private static function nativeLines(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];

        $new = $changes['attributes'] ?? null;
        $old = $changes['old'] ?? null;

        if (! is_array($new) || ! is_array($old)) {
            return [];
        }

        $lines = [];

        foreach ($new as $key => $value) {
            $before = self::stringify($old[$key] ?? null);
            $after = self::stringify($value);

            if ($before === $after) {
                continue;
            }

            $lines[] = Str::headline((string) $key).': '.$before.self::ARROW.$after;
        }

        return $lines;
    }

    /**
     * Each custom field that moved is logged as its own row. The audit table
     * collapses a save to a single row, so the survivor carries every sibling
     * payload — including its own — aggregated into
     * `batch_custom_field_properties`. Rows written outside a batch have no
     * aggregate and speak for themselves.
     *
     * @return list<string>
     */
    private static function customFieldLines(Activity $activity): array
    {
        $payloads = self::aggregatedPayloads($activity)
            ?? [$activity->properties?->toArray() ?? []];

        $lines = [];

        foreach ($payloads as $payload) {
            $changes = $payload['custom_field_changes'] ?? null;

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $label = $change['label'] ?? $change['code'] ?? null;
                $before = self::stringify($change['old'] ?? null);
                $after = self::stringify($change['new'] ?? null);

                if (! is_string($label) || $before === $after) {
                    continue;
                }

                $line = $label.': '.$before.self::ARROW.$after;

                if (! in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function aggregatedPayloads(Activity $activity): ?array
    {
        $aggregate = $activity->getAttribute('batch_custom_field_properties');

        if (! is_string($aggregate)) {
            return null;
        }

        $decoded = json_decode($aggregate, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    private static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            $label = $value['label'] ?? null;

            return is_string($label) && $label !== '' ? $label : self::EMPTY;
        }

        if (in_array($value, [null, '', []], true)) {
            return self::EMPTY;
        }

        if (is_bool($value)) {
            return $value ? __('teams.activity.yes') : __('teams.activity.no');
        }

        return is_scalar($value) ? (string) $value : self::EMPTY;
    }
}

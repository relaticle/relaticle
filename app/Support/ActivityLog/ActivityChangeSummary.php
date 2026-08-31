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
    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    public static function for(Activity $activity): array
    {
        return [
            ...self::nativeChanges($activity),
            ...self::customFieldChanges($activity),
        ];
    }

    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    private static function nativeChanges(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];

        $new = $changes['attributes'] ?? null;
        $old = $changes['old'] ?? null;

        if (! is_array($new) || ! is_array($old)) {
            return [];
        }

        $rows = [];

        foreach ($new as $key => $value) {
            $before = ActivityValue::display($old[$key] ?? null);
            $after = ActivityValue::display($value);

            if ($before === $after) {
                continue;
            }

            $rows[] = [
                'label' => Str::headline((string) $key),
                'old' => $before,
                'new' => $after,
            ];
        }

        return $rows;
    }

    /**
     * Each custom field that moved is logged as its own row. The audit table
     * collapses a save to a single row, so the survivor carries every sibling
     * payload, including its own, aggregated into
     * `batch_custom_field_properties`. Rows written outside a batch have no
     * aggregate and speak for themselves.
     *
     * @return list<array{label: string, old: string, new: string}>
     */
    private static function customFieldChanges(Activity $activity): array
    {
        $payloads = self::aggregatedPayloads($activity)
            ?? [$activity->properties?->toArray() ?? []];

        $rows = [];

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
                $before = ActivityValue::display($change['old'] ?? null);
                $after = ActivityValue::display($change['new'] ?? null);

                if (! is_string($label) || $before === $after) {
                    continue;
                }

                $row = ['label' => $label, 'old' => $before, 'new' => $after];

                if (! in_array($row, $rows, true)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
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
}

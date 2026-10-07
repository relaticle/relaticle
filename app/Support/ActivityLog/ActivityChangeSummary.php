<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Models\ActivityLog\Activity;
use App\Support\CustomFieldSettingsSchema;
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
     * @return list<array{label: string, old: string, new: string, full: array{old: string, new: string}|null}>
     */
    public static function for(Activity $activity): array
    {
        return [
            ...self::nativeChanges($activity),
            ...self::customFieldChanges($activity),
        ];
    }

    /**
     * @return list<array{label: string, old: string, new: string, full: array{old: string, new: string}|null}>
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
            if ($key === 'settings' && is_array($value)) {
                array_push($rows, ...self::settingsChanges(is_array($old[$key] ?? null) ? $old[$key] : [], $value));

                continue;
            }

            $row = ActivityValue::row(Str::headline((string) $key), $old[$key] ?? null, $value);

            if (ActivityValue::isUnchanged($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{label: string, old: string, new: string, full: array{old: string, new: string}|null}>
     */
    private static function settingsChanges(array $old, array $new): array
    {
        $flatten = fn (array $settings): array => [
            ...array_filter($settings, fn (mixed $value, string $key): bool => $key !== 'additional' && ! is_array($value), ARRAY_FILTER_USE_BOTH),
            ...(is_array($settings['additional'] ?? null) ? $settings['additional'] : []),
        ];

        $before = $flatten($old);
        $after = $flatten($new);
        $rows = [];

        foreach ($after as $key => $value) {
            $oldDisplay = CustomFieldSettingsSchema::displayValue((string) $key, $before[$key] ?? null);
            $newDisplay = CustomFieldSettingsSchema::displayValue((string) $key, $value);

            if ($oldDisplay === $newDisplay) {
                continue;
            }

            $rows[] = [
                'label' => CustomFieldSettingsSchema::label((string) $key),
                'old' => $oldDisplay,
                'new' => $newDisplay,
                'full' => null,
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
     * @return list<array{label: string, old: string, new: string, full: array{old: string, new: string}|null}>
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
                if (! is_string($label)) {
                    continue;
                }

                $row = ActivityValue::row($label, $change['old'] ?? null, $change['new'] ?? null);

                if (ActivityValue::isUnchanged($row)) {
                    continue;
                }

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

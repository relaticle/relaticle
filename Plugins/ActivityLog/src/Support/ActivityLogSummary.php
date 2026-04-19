<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Support;

use Illuminate\Support\Str;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final readonly class ActivityLogSummary
{
    /**
     * @param  list<ActivityLogDiffRow>  $diffRows
     * @param  list<string>  $changedFieldLabels
     */
    public function __construct(
        public string $causerName,
        public ?ActivityLogOperation $operation,
        public array $changedFieldLabels,
        public string $summarySentence,
        public array $diffRows,
        public bool $hasDiff,
    ) {}

    public static function from(TimelineEntry $entry): self
    {
        $causerName = $entry->causer?->getAttribute('name');
        $causerName = is_string($causerName) && $causerName !== '' ? $causerName : 'System';

        $operation = ActivityLogOperation::tryFrom($entry->event);

        /** @var array<string, mixed> $new */
        $new = self::extractAttributes($entry->properties, 'attributes');
        /** @var array<string, mixed> $old */
        $old = self::extractAttributes($entry->properties, 'old');

        $keys = array_values(array_unique([...array_keys($new), ...array_keys($old)]));
        $keys = array_values(array_filter($keys, self::isPublicKey(...)));

        $labels = array_map(static fn (string $key): string => Str::headline($key), $keys);

        $diffRows = [];
        foreach ($keys as $key) {
            $diffRows[] = new ActivityLogDiffRow(
                label: Str::headline($key),
                old: $old[$key] ?? null,
                new: $new[$key] ?? null,
            );
        }

        return new self(
            causerName: $causerName,
            operation: $operation,
            changedFieldLabels: $labels,
            summarySentence: self::composeSentence($causerName, $operation, $entry, $labels),
            diffRows: $diffRows,
            hasDiff: $operation === ActivityLogOperation::Updated && $diffRows !== [],
        );
    }

    /**
     * @param  list<string>  $labels
     */
    private static function composeSentence(string $causer, ?ActivityLogOperation $operation, TimelineEntry $entry, array $labels): string
    {
        if ($operation === ActivityLogOperation::Updated) {
            $count = count($labels);

            if ($count === 0) {
                return (string) __('activity-log::messages.summary.updated', [
                    'causer' => $causer,
                    'subject' => self::subjectNoun($entry),
                ]);
            }

            if ($count === 1) {
                return (string) __('activity-log::messages.summary.changed_field', [
                    'causer' => $causer,
                    'field' => $labels[0],
                ]);
            }

            return (string) __('activity-log::messages.summary.changed_attributes', [
                'causer' => $causer,
                'count' => $count,
            ]);
        }

        $verb = $operation?->verb() ?? Str::lower(Str::headline($entry->event));

        return (string) __('activity-log::messages.summary.fallback', [
            'causer' => $causer,
            'verb' => $verb,
            'subject' => self::subjectNoun($entry),
        ]);
    }

    private static function subjectNoun(TimelineEntry $entry): string
    {
        $subject = $entry->subject;

        if ($subject !== null) {
            $name = $subject->getAttribute('name');

            if (is_string($name) && $name !== '') {
                return $name;
            }

            return Str::lower(Str::headline(class_basename($subject::class)));
        }

        return (string) __('activity-log::messages.summary.this_record');
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function extractAttributes(array $properties, string $key): array
    {
        $value = $properties[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private static function isPublicKey(string $key): bool
    {
        if (str_starts_with($key, '_')) {
            return false;
        }

        return ! in_array($key, ['created_at', 'updated_at', 'deleted_at'], true);
    }
}

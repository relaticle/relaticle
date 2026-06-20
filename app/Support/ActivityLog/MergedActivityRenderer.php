<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use Illuminate\Contracts\View\View;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Support\ActivityLogSummary;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

/**
 * Renders one same-save group — native column changes and custom-field changes
 * that shared a `batch_uuid` — as a single timeline entry. The package's
 * batch-merge unions every grouped row's payload into `$entry->properties`, so
 * both the native `attributes`/`old` maps and the `custom_field_changes` list
 * arrive here together.
 */
final readonly class MergedActivityRenderer implements TimelineRenderer
{
    public function render(TimelineEntry $entry): View
    {
        $summary = ActivityLogSummary::from($entry);

        return view('activity-log.merged-activity', [
            'entry' => $entry,
            'summary' => $summary,
            'rows' => $this->rows($entry, $summary),
        ]);
    }

    /**
     * Flatten native diff rows and custom-field changes into one ordered list of
     * label / old / new triples for the diff table.
     *
     * @return list<array{label: string, old: string, new: string}>
     */
    private function rows(TimelineEntry $entry, ActivityLogSummary $summary): array
    {
        $rows = [];

        foreach ($summary->diffRows as $row) {
            $rows[] = [
                'label' => $row->label,
                'old' => $row->formattedOld(),
                'new' => $row->formattedNew(),
            ];
        }

        /** @var list<array<string, mixed>> $changes */
        $changes = $entry->properties['custom_field_changes'] ?? [];

        foreach ($changes as $change) {
            $label = $change['label'] ?? $change['code'] ?? '';
            $rows[] = [
                'label' => is_string($label) ? $label : '',
                'old' => $this->customFieldLabel($change['old'] ?? null),
                'new' => $this->customFieldLabel($change['new'] ?? null),
            ];
        }

        return $rows;
    }

    private function customFieldLabel(mixed $side): string
    {
        $label = is_array($side) ? ($side['label'] ?? null) : null;

        return is_string($label) && $label !== '' ? $label : '—';
    }
}

<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use Illuminate\Contracts\View\View;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final readonly class CustomFieldChangesRenderer implements TimelineRenderer
{
    public function render(TimelineEntry $entry): View
    {
        /** @var list<array<string, mixed>> $changes */
        $changes = $entry->properties['custom_field_changes'] ?? [];

        return view('activity-log.custom-field-changes', [
            'entry' => $entry,
            'changes' => $changes,
        ]);
    }
}

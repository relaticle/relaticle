<?php

declare(strict_types=1);

namespace App\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

enum AppEventPalette: string implements HasIcon, HasLabel
{
    case NoteCreated = 'note_created';
    case TaskCreated = 'task_created';
    case RecordMerged = 'record_merged';

    public function getIcon(): string
    {
        return match ($this) {
            self::NoteCreated => 'lucide-sticky-note',
            self::TaskCreated => 'lucide-circle-check',
            self::RecordMerged => 'lucide-merge',
        };
    }

    public function getLabel(): string
    {
        return (string) __("activity-log.events.{$this->value}.label");
    }

    public function title(): ?string
    {
        return match ($this) {
            self::RecordMerged => $this->getLabel(),
            default => null,
        };
    }

    public function description(TimelineEntry $entry): ?string
    {
        return match ($this) {
            self::RecordMerged => (string) __('activity-log.events.record_merged.description', ['name' => $entry->properties['duplicate_name'] ?? '']),
            default => null,
        };
    }

    public function badge(): null
    {
        return null;
    }
}

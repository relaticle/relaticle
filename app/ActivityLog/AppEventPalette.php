<?php

declare(strict_types=1);

namespace App\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AppEventPalette: string implements HasIcon, HasLabel
{
    case NoteCreated = 'note_created';
    case TaskCreated = 'task_created';

    public function getIcon(): string
    {
        return match ($this) {
            self::NoteCreated => 'lucide-sticky-note',
            self::TaskCreated => 'lucide-circle-check',
        };
    }

    public function getLabel(): string
    {
        return (string) __("activity-log.events.{$this->value}.label");
    }

    public function badge(): null
    {
        return null;
    }
}

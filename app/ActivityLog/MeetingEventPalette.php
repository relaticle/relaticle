<?php

declare(strict_types=1);

namespace App\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MeetingEventPalette: string implements HasIcon, HasLabel
{
    case MeetingCreated = 'meeting.created';
    case MeetingCancelled = 'meeting.cancelled';

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::MeetingCreated => Heroicon::OutlinedCalendar,
            self::MeetingCancelled => Heroicon::OutlinedCalendarDays,
        };
    }

    public function getLabel(): string
    {
        $events = __('activity-log.events');
        $event = is_array($events) ? ($events[$this->value] ?? null) : null;
        $label = is_array($event) ? ($event['label'] ?? null) : null;

        return is_string($label) ? $label : $this->value;
    }

    public function badge(): null
    {
        return null;
    }
}

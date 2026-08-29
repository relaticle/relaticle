<?php

declare(strict_types=1);

namespace App\ActivityLog;

enum MeetingEventPalette: string
{
    case MeetingCreated = 'meeting.created';
    case MeetingCancelled = 'meeting.cancelled';

    public function icon(): string
    {
        return match ($this) {
            self::MeetingCreated => 'heroicon-o-calendar',
            self::MeetingCancelled => 'heroicon-o-calendar-days',
        };
    }

    public function label(): string
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

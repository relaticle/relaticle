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
        return (string) __("activity-log.events.{$this->value}.label");
    }

    public function badge(): null
    {
        return null;
    }
}

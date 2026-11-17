<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MeetingEventPalette: string implements HasIcon, HasLabel
{
    case MeetingCreated = 'meeting.created';
    case MeetingCancelled = 'meeting.cancelled';

    public function getIcon(): string
    {
        return match ($this) {
            self::MeetingCreated => 'lucide-calendar',
            self::MeetingCancelled => 'lucide-calendar-days',
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

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

enum EmailEventPalette: string implements HasIcon, HasLabel
{
    case EmailSent = 'email_sent';
    case EmailReceived = 'email_received';

    public function getIcon(): string
    {
        return 'lucide-mail';
    }

    public function getLabel(): string
    {
        return (string) __("activity-log.events.{$this->value}.label");
    }

    public function title(): null
    {
        return null;
    }

    public function description(TimelineEntry $entry): null
    {
        return null;
    }

    /**
     * @return array{text: string, tone: string}
     */
    public function badge(): array
    {
        return match ($this) {
            self::EmailSent => ['text' => (string) __('activity-log.events.email_sent.badge'), 'tone' => 'primary'],
            self::EmailReceived => ['text' => (string) __('activity-log.events.email_received.badge'), 'tone' => 'success'],
        };
    }
}

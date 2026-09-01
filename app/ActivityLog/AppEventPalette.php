<?php

declare(strict_types=1);

namespace App\ActivityLog;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AppEventPalette: string implements HasIcon, HasLabel
{
    case EmailSent = 'email_sent';
    case EmailReceived = 'email_received';
    case NoteCreated = 'note_created';
    case TaskCreated = 'task_created';

    public function getIcon(): string
    {
        return match ($this) {
            self::EmailSent, self::EmailReceived => 'ri-mail-line',
            self::NoteCreated => 'ri-sticky-note-line',
            self::TaskCreated => 'ri-checkbox-circle-line',
        };
    }

    public function getLabel(): string
    {
        return (string) __("activity-log.events.{$this->value}.label");
    }

    /**
     * @return array{text: string, tone: string}|null
     */
    public function badge(): ?array
    {
        return match ($this) {
            self::EmailSent => ['text' => (string) __('activity-log.events.email_sent.badge'), 'tone' => 'primary'],
            self::EmailReceived => ['text' => (string) __('activity-log.events.email_received.badge'), 'tone' => 'success'],
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';

    public function label(): string
    {
        return __("notifications.channels.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::InApp => 'ri-smartphone-line',
            self::Email => 'ri-mail-line',
        };
    }
}

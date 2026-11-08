<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum RequestOutcome: string
{
    case Approved = 'approved';
    case Denied = 'denied';
    case AlreadyDecided = 'already_decided';

    public function label(): string
    {
        return match ($this) {
            self::Approved => __('notifications.panel.requests.approved'),
            self::Denied => __('notifications.panel.requests.denied'),
            self::AlreadyDecided => __('notifications.panel.requests.already_decided'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'lucide-check',
            self::Denied => 'lucide-x',
            self::AlreadyDecided => 'lucide-info',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Denied, self::AlreadyDecided => 'gray',
        };
    }

    public function announcement(string $title): string
    {
        return match ($this) {
            self::Approved => __('notifications.panel.announcements.approved', ['title' => $title]),
            self::Denied => __('notifications.panel.announcements.denied', ['title' => $title]),
            self::AlreadyDecided => __('notifications.panel.announcements.already_decided', ['title' => $title]),
        };
    }
}

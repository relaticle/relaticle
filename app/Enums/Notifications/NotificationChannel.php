<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
}

<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationGroup: string
{
    case Collaboration = 'collaboration';
    case Digest = 'digest';
}

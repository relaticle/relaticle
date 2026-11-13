<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationReason: string
{
    case Assigned = 'assigned';
    case Requested = 'requested';
    case Shared = 'shared';
    case System = 'system';
}

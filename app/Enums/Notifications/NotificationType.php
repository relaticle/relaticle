<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case TaskDigest = 'task_digest';
}

<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum DigestCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Off = 'off';
}

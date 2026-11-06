<?php

declare(strict_types=1);

namespace App\Enums;

enum DeletionReason: string
{
    case Requested = 'requested';
    case Inactivity = 'inactivity';
}

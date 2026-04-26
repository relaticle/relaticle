<?php

declare(strict_types=1);

namespace App\Enums;

enum TagAction: string
{
    case Add = 'add';
    case Remove = 'remove';
}

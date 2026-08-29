<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialiteProvider: string
{
    case GOOGLE = 'google';
    case MICROSOFT = 'microsoft';
}

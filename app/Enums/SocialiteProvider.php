<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialiteProvider: string
{
    case GOOGLE = 'google';
    case MICROSOFT = 'microsoft';

    public function icon(): string
    {
        return match ($this) {
            self::GOOGLE => 'ri-google-fill',
            self::MICROSOFT => 'ri-microsoft-fill',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaCollection: string
{
    case Logo = 'logo';
    case PendingUploads = 'pending-uploads';

    public static function forCustomField(string $code): string
    {
        return "custom-field-{$code}";
    }
}

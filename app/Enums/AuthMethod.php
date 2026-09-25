<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthMethod: string
{
    case PASSWORD = 'password';
    case PASSKEY = 'passkey';
    case GOOGLE = 'google';
    case MICROSOFT = 'microsoft';
    case EMAIL = 'email';
    case SIGNUP = 'signup';
    case REMEMBERED = 'remembered';
}

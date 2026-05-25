<?php

declare(strict_types=1);

namespace App\Enums;

enum TeamRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum FavoriteKind: string
{
    case Record = 'record';
    case Folder = 'folder';
}

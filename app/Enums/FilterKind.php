<?php

declare(strict_types=1);

namespace App\Enums;

enum FilterKind: string
{
    case Text = 'text';
    case DateTime = 'date-time';
    case Enum = 'enum';
    case Members = 'members';
    case Relation = 'relation';
    case Computed = 'computed';
}

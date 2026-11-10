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

    public function filterFieldKind(): FilterFieldKind
    {
        return match ($this) {
            self::Text => FilterFieldKind::Text,
            self::DateTime => FilterFieldKind::Date,
            self::Enum => FilterFieldKind::Choice,
            self::Members => FilterFieldKind::Member,
            self::Relation => FilterFieldKind::Relation,
            self::Computed => FilterFieldKind::Inactivity,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Notifications;

enum SavedViewChange: string
{
    case Deleted = 'deleted';
    case Unshared = 'unshared';
    case Renamed = 'renamed';
    case Updated = 'updated';

    public function icon(): string
    {
        return match ($this) {
            self::Deleted => 'lucide-trash-2',
            self::Unshared => 'lucide-lock',
            self::Renamed, self::Updated => 'lucide-square-pen',
        };
    }
}

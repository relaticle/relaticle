<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Support;

enum ActivityLogOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';

    public function icon(): string
    {
        return match ($this) {
            self::Created => 'ri-add-line',
            self::Deleted => 'ri-delete-bin-line',
            self::Restored => 'ri-arrow-go-back-line',
            self::Updated => 'ri-edit-line',
        };
    }

    public function verb(): string
    {
        return $this->value;
    }
}

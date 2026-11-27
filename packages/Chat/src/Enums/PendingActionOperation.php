<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum PendingActionOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Merge = 'merge';

    public function action(): string
    {
        return match ($this) {
            self::Create => __('Create'),
            self::Update => __('Save changes'),
            self::Delete => __('Delete'),
            self::Merge => __('Merge'),
        };
    }

    public function done(): string
    {
        return match ($this) {
            self::Create => __('Created'),
            self::Update => __('Updated'),
            self::Delete => __('Deleted'),
            self::Merge => __('Merged'),
        };
    }

    public function verb(): string
    {
        return $this->value;
    }

    public function notDone(): string
    {
        return match ($this) {
            self::Create => 'NOT created',
            self::Update => 'NOT updated',
            self::Delete => 'NOT deleted',
            self::Merge => 'NOT merged',
        };
    }

    public function writesFields(): bool
    {
        return match ($this) {
            self::Create, self::Update => true,
            self::Delete, self::Merge => false,
        };
    }
}

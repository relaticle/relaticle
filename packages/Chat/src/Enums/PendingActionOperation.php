<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum PendingActionOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function action(): string
    {
        return match ($this) {
            self::Create => __('Create'),
            self::Update => __('Save changes'),
            self::Delete => __('Delete'),
        };
    }

    public function done(): string
    {
        return match ($this) {
            self::Create => __('Created'),
            self::Update => __('Updated'),
            self::Delete => __('Deleted'),
        };
    }

    public function count(): string
    {
        return match ($this) {
            self::Create => __(':count created'),
            self::Update => __(':count updated'),
            self::Delete => __(':count deleted'),
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
        };
    }
}

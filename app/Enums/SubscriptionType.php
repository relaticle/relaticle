<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionType: string
{
    case Default = 'default';
    case Managed = 'managed';
    case ManagedPending = 'managed_pending';

    /** @return list<string> */
    public static function managed(): array
    {
        return [self::Managed->value, self::ManagedPending->value];
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum PendingActionOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}

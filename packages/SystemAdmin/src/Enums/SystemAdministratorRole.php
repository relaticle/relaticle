<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Enums;

enum SystemAdministratorRole: string
{
    case SuperAdministrator = 'super_administrator';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
        };
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

// Match, not a comparison: this package is excluded from PHPStan, so a role added
// without a decision here has to fail loudly rather than default to allowed.
enum SystemAdministratorRole: string implements HasColor, HasLabel
{
    case SuperAdministrator = 'super_administrator';
    case Administrator = 'administrator';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::Administrator => 'Administrator',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'danger',
            self::Administrator => 'warning',
        };
    }

    public function canDelete(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }

    public function canAdministerStaff(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }

    public function canManageCustomerAccess(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }

    /**
     * Impersonation grants everything the customer can do, which includes the
     * record edits canManageCustomerAccess() withholds from an Administrator.
     */
    public function canImpersonate(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }
}

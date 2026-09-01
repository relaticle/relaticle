<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailAccountStatus: string implements HasColor, HasLabel
{
    case ACTIVE = 'active';
    case ERROR = 'error';
    case DISCONNECTED = 'disconnected';
    case REAUTH_REQUIRED = 'reauth_required';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::ERROR => 'Sync Error',
            self::DISCONNECTED => 'Disconnected',
            self::REAUTH_REQUIRED => 'Re-authentication Required',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::ERROR => 'danger',
            self::DISCONNECTED => 'gray',
            self::REAUTH_REQUIRED => 'warning',
        };
    }
}

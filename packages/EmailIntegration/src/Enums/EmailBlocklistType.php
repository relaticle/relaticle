<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailBlocklistType: string implements HasLabel
{
    case EMAIL = 'email';
    case DOMAIN = 'domain';

    public function getLabel(): string
    {
        return match ($this) {
            self::EMAIL => 'Email address',
            self::DOMAIN => 'Domain',
        };
    }
}

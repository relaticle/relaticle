<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailPriority: string implements HasColor, HasLabel
{
    case PRIORITY = 'priority';
    case BULK = 'bulk';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRIORITY => 'Priority',
            self::BULK => 'Bulk',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PRIORITY => 'info',
            self::BULK => 'gray',
        };
    }

    public function queueName(): string
    {
        return match ($this) {
            self::PRIORITY => 'emails-priority',
            self::BULK => 'emails-bulk',
        };
    }
}

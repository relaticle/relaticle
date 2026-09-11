<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailDirection: string implements HasLabel
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';

    public function getLabel(): string
    {
        return match ($this) {
            self::INBOUND => 'Received',
            self::OUTBOUND => 'Sent',
        };
    }
}

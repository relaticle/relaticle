<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailCreationSource: string implements HasLabel
{
    case SYNC = 'sync';
    case COMPOSE = 'compose';
    case FORWARD = 'forward';
    case BCC_INBOUND = 'bcc_inbound';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNC => 'Sync',
            self::COMPOSE => 'Compose',
            self::FORWARD => 'Forward',
            self::BCC_INBOUND => 'BCC Inbound',
        };
    }
}

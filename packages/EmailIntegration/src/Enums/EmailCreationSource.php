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
    case REPLY = 'reply';
    case REPLY_ALL = 'reply_all';
    case MASS_SEND = 'mass_send';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNC => 'Sync',
            self::COMPOSE => 'Compose',
            self::FORWARD => 'Forward',
            self::BCC_INBOUND => 'BCC Inbound',
            self::REPLY => 'Reply',
            self::REPLY_ALL => 'Reply All',
            self::MASS_SEND => 'Mass Send',
        };
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum OutboxTab: string implements HasLabel
{
    case QUEUED = 'queued';
    case SCHEDULED = 'scheduled';
    case SENDING = 'sending';
    case FAILED = 'failed';
    case SENT = 'sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::SCHEDULED => 'Scheduled',
            self::SENDING => 'Sending',
            self::FAILED => 'Failed',
            self::SENT => 'Sent (24h)',
        };
    }
}

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailStatus: string implements HasColor, HasLabel
{
    case SYNCED = 'synced';
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNCED => 'Synced',
            self::DRAFT => 'Draft',
            self::QUEUED => 'Queued',
            self::SENDING => 'Sending',
            self::SENT => 'Sent',
            self::FAILED => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SYNCED => 'gray',
            self::DRAFT => 'info',
            self::QUEUED, self::SENDING => 'warning',
            self::SENT => 'success',
            self::FAILED => 'danger',
        };
    }
}

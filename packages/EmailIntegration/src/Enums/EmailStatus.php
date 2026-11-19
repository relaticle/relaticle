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
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNCED => __('enums/email-status.synced'),
            self::DRAFT => __('enums/email-status.draft'),
            self::QUEUED => __('enums/email-status.queued'),
            self::SENDING => __('enums/email-status.sending'),
            self::SENT => __('enums/email-status.sent'),
            self::FAILED => __('enums/email-status.failed'),
            self::CANCELLED => __('enums/email-status.cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SYNCED => 'gray',
            self::DRAFT, self::QUEUED => 'info',
            self::SENDING => 'warning',
            self::SENT => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'gray',
        };
    }
}

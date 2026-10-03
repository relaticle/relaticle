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
            self::QUEUED => __('filament/pages/email-outbox.tabs.queued'),
            self::SCHEDULED => __('filament/pages/email-outbox.tabs.scheduled'),
            self::SENDING => __('filament/pages/email-outbox.tabs.sending'),
            self::FAILED => __('filament/pages/email-outbox.tabs.failed'),
            self::SENT => __('filament/pages/email-outbox.tabs.sent'),
        };
    }
}

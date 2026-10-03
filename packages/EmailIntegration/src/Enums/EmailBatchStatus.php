<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailBatchStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Completed = 'completed';
    case PartialFailure = 'partial_failure';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => __('filament/pages/email-outbox.batch_statuses.queued'),
            self::Sending => __('filament/pages/email-outbox.batch_statuses.sending'),
            self::Completed => __('filament/pages/email-outbox.batch_statuses.completed'),
            self::PartialFailure => __('filament/pages/email-outbox.batch_statuses.partial_failure'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Sending => 'info',
            self::Completed => 'success',
            self::PartialFailure => 'danger',
        };
    }
}

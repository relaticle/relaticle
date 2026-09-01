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
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Completed => 'Completed',
            self::PartialFailure => 'Partial Failure',
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

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CalendarEventStatus: string implements HasColor, HasLabel
{
    case CONFIRMED = 'confirmed';
    case TENTATIVE = 'tentative';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Confirmed',
            self::TENTATIVE => 'Tentative',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CONFIRMED => 'success',
            self::TENTATIVE => 'warning',
            self::CANCELLED => 'gray',
        };
    }
}

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
            self::CONFIRMED => __('enums/calendar-event-status.confirmed'),
            self::TENTATIVE => __('enums/calendar-event-status.tentative'),
            self::CANCELLED => __('enums/calendar-event-status.cancelled'),
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

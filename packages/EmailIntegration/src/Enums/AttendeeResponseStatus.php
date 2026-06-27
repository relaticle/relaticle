<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AttendeeResponseStatus: string implements HasColor, HasLabel
{
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case TENTATIVE = 'tentative';
    case NEEDS_ACTION = 'needsAction';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Accepted',
            self::DECLINED => 'Declined',
            self::TENTATIVE => 'Maybe',
            self::NEEDS_ACTION => 'No Response',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACCEPTED => 'success',
            self::DECLINED => 'danger',
            self::TENTATIVE => 'warning',
            self::NEEDS_ACTION => 'gray',
        };
    }
}

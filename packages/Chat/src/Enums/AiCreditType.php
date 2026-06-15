<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AiCreditType: string implements HasColor, HasLabel
{
    case Chat = 'chat';
    case Summary = 'summary';
    case Embedding = 'embedding';
    case Adjustment = 'adjustment';
    case Refund = 'refund';
    case Reservation = 'reservation';

    public function getColor(): string
    {
        return match ($this) {
            self::Chat => 'primary',
            self::Summary => 'info',
            self::Embedding, self::Reservation => 'gray',
            self::Adjustment => 'warning',
            self::Refund => 'success',
        };
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

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
    case Purchase = 'purchase';
    case Internal = 'internal';

    public function consumesCredits(): bool
    {
        return match ($this) {
            self::Chat, self::Summary, self::Embedding => true,
            self::Adjustment, self::Refund, self::Reservation, self::Purchase, self::Internal => false,
        };
    }

    /** @return list<self> */
    public static function spending(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->consumesCredits()));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Chat => 'primary',
            self::Summary => 'info',
            self::Embedding, self::Reservation, self::Internal => 'gray',
            self::Adjustment => 'warning',
            self::Refund => 'success',
            self::Purchase => 'success',
        };
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

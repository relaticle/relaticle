<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Services\EmailClassifier;

/**
 * Canonical vocabulary for email classification.
 *
 * Produced by {@see EmailClassifier} from deterministic rules during sync.
 * Values are stored verbatim on `email_labels.label`, so they double as the
 * on-the-wire contract — keep them stable.
 */
enum EmailCategory: string implements HasColor, HasIcon, HasLabel
{
    case Scheduling = 'Scheduling';
    case Marketing = 'Marketing';
    case Invoice = 'Invoice';
    case Support = 'Support';
    case Sales = 'Sales';
    case Personal = 'Personal';
    case Other = 'Other';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduling => 'info',
            self::Marketing => 'warning',
            self::Invoice => 'success',
            self::Support => 'primary',
            self::Sales => 'danger',
            self::Personal, self::Other => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Scheduling => Heroicon::CalendarDays,
            self::Marketing => Heroicon::Megaphone,
            self::Invoice => Heroicon::Banknotes,
            self::Support => Heroicon::Lifebuoy,
            self::Sales => Heroicon::ArrowTrendingUp,
            self::Personal => Heroicon::User,
            self::Other => Heroicon::Tag,
        };
    }
}

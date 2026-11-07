<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Relaticle\EmailIntegration\Services\EmailClassifier;

/**
 * Canonical vocabulary for email classification.
 *
 * Produced by {@see EmailClassifier} from deterministic rules during sync.
 * Values are stored verbatim on `email_labels.label`, so they double as the
 * on-the-wire contract, so keep them stable.
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

    public function getIcon(): string
    {
        return match ($this) {
            self::Scheduling => 'lucide-calendar-days',
            self::Marketing => 'lucide-megaphone',
            self::Invoice => 'lucide-banknote',
            self::Support => 'lucide-life-buoy',
            self::Sales => 'lucide-trending-up',
            self::Personal => 'lucide-user',
            self::Other => 'lucide-tag',
        };
    }

    /**
     * Other is the unmatched fallback. It is stored, not shown.
     */
    public function isVisible(): bool
    {
        return $this !== self::Other;
    }
}

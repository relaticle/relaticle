<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ContactCreationMode: string implements HasDescription, HasIcon, HasLabel
{
    case All = 'all';

    case Selective = 'selective';

    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('filament/pages/email-privacy-settings.record_creation.modes.all.label'),
            self::Selective => __('filament/pages/email-privacy-settings.record_creation.modes.selective.label'),
            self::None => __('filament/pages/email-privacy-settings.record_creation.modes.none.label'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::All => __('filament/pages/email-privacy-settings.record_creation.modes.all.description'),
            self::Selective => __('filament/pages/email-privacy-settings.record_creation.modes.selective.description'),
            self::None => __('filament/pages/email-privacy-settings.record_creation.modes.none.description'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::All => 'lucide-users',
            self::Selective => 'lucide-user-plus',
            self::None => 'lucide-ban',
        };
    }

    public function isRecommended(): bool
    {
        return $this === self::Selective;
    }
}

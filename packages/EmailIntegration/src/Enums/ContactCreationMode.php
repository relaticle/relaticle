<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

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

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::All => Heroicon::OutlinedUserGroup,
            self::Selective => Heroicon::OutlinedUserPlus,
            self::None => Heroicon::OutlinedNoSymbol,
        };
    }

    public function isRecommended(): bool
    {
        return $this === self::Selective;
    }
}

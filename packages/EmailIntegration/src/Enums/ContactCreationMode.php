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

    case Bidirectional = 'bidirectional';

    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('filament/pages/email-privacy-settings.record_creation.modes.all.label'),
            self::Bidirectional => __('filament/pages/email-privacy-settings.record_creation.modes.bidirectional.label'),
            self::None => __('filament/pages/email-privacy-settings.record_creation.modes.none.label'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::All => __('filament/pages/email-privacy-settings.record_creation.modes.all.description'),
            self::Bidirectional => __('filament/pages/email-privacy-settings.record_creation.modes.bidirectional.description'),
            self::None => __('filament/pages/email-privacy-settings.record_creation.modes.none.description'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::All => Heroicon::OutlinedUserGroup,
            self::Bidirectional => Heroicon::OutlinedUserPlus,
            self::None => Heroicon::OutlinedNoSymbol,
        };
    }

    public function isRecommended(): bool
    {
        return $this === self::Bidirectional;
    }
};

<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum EmailVisibilityEnforcement: string implements HasDescription, HasLabel
{
    case Protected = 'protected';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Protected => __('filament/pages/email-privacy-settings.visibility.enforcement.protected.label'),
            self::Blocked => __('filament/pages/email-privacy-settings.visibility.enforcement.blocked.label'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Protected => __('filament/pages/email-privacy-settings.visibility.enforcement.protected.description'),
            self::Blocked => __('filament/pages/email-privacy-settings.visibility.enforcement.blocked.description'),
        };
    }
}

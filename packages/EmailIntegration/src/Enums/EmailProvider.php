<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EmailProvider: string implements HasColor, HasIcon, HasLabel
{
    case GMAIL = 'gmail';
    case AZURE = 'azure';

    public function isConfigured(): bool
    {
        return match ($this) {
            self::GMAIL => true,
            self::AZURE => filled(config('services.azure.client_id')),
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::GMAIL => 'Gmail',
            self::AZURE => 'Outlook / Microsoft 365',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::GMAIL => 'danger',
            self::AZURE => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::GMAIL => 'icon-google',
            self::AZURE => 'icon-microsoft',
        };
    }
}

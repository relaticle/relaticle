<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SortDirection: string implements HasIcon, HasLabel
{
    case Desc = 'desc';
    case Asc = 'asc';

    public function getLabel(): string
    {
        return match ($this) {
            self::Desc => __('enums/sort-direction.desc'),
            self::Asc => __('enums/sort-direction.asc'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Desc => 'lucide-arrow-down-wide-narrow',
            self::Asc => 'lucide-arrow-up-narrow-wide',
        };
    }
}

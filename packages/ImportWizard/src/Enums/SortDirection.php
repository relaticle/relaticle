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
            self::Desc => 'Descending',
            self::Asc => 'Ascending',
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

<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SortField: string implements HasIcon, HasLabel
{
    case Count = 'count';
    case Value = 'raw_value';

    public function getLabel(): string
    {
        return match ($this) {
            self::Count => 'Row count',
            self::Value => 'Value',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Count => 'heroicon-o-hashtag',
            self::Value => 'heroicon-o-language',
        };
    }
}

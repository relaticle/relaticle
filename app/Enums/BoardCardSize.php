<?php

declare(strict_types=1);

namespace App\Enums;

enum BoardCardSize: string
{
    case Compact = 'compact';
    case Full = 'full';

    public function label(): string
    {
        return __("filament/pages/boards.layout.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Compact => 'lucide-rows-3',
            self::Full => 'lucide-square',
        };
    }
}

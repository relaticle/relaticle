<?php

declare(strict_types=1);

namespace App\Enums;

enum BoardColumnWidth: string
{
    case Narrow = 'narrow';
    case Wide = 'wide';

    public function label(): string
    {
        return __("filament/pages/boards.layout.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Narrow => 'lucide-columns-4',
            self::Wide => 'lucide-columns-2',
        };
    }
}

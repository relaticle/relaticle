<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SavedViewType: string implements HasIcon, HasLabel
{
    case Table = 'table';
    case Board = 'board';

    public function getLabel(): string
    {
        return __("filament/panel.tables.views.types.{$this->value}");
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Table => 'lucide-table-2',
            self::Board => 'lucide-square-kanban',
        };
    }

    public function getDescription(): string
    {
        return __("filament/panel.tables.views.type_descriptions.{$this->value}");
    }
}
